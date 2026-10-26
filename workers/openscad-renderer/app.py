import os
import uuid
import re
import shutil
import logging
import asyncio
import base64
from typing import Optional, List, Union, Tuple
from collections import Counter
from PIL import Image
from fastapi import FastAPI, Request, BackgroundTasks, HTTPException, Form
from fastapi.responses import FileResponse
from pydantic_settings import BaseSettings, SettingsConfigDict

from mcp.server import Server
from mcp.types import Tool, TextContent, ImageContent
from mcp.server.sse import SseServerTransport

# ====================== 1. Настройки ======================
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))

class Settings(BaseSettings):
    host: str = "0.0.0.0"
    port: int = 5000
    base_dir: str = SCRIPT_DIR
    files_dir: str = os.path.join(SCRIPT_DIR, "files")
    bin_dir: str = os.path.join(SCRIPT_DIR, "bin")
    # Директория со сторонними библиотеками (где лежит BOSL2/)
    libraries_dir: str = os.path.join(SCRIPT_DIR, "libraries")
    openscad_timeout: int = 90
    max_code_size: int = 300_000
    base_url: str = "https://openscad.toolforge.ru"

    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

settings = Settings()
os.makedirs(settings.files_dir, exist_ok=True)
os.makedirs(settings.bin_dir, exist_ok=True)
os.makedirs(settings.libraries_dir, exist_ok=True)

logging.basicConfig(level=logging.INFO, format="%(asctime)s - %(levelname)s - %(message)s")
logger = logging.getLogger("openscad-renderer")

class OpenSCADError(Exception):
    def __init__(self, message: str, stderr: str = ""):
        super().__init__(message)
        self.stderr = stderr

def resolve_binary(name: str) -> Optional[str]:
    local_bin = os.path.join(settings.bin_dir, name)
    if os.path.isfile(local_bin) and os.access(local_bin, os.X_OK):
        return local_bin
    return shutil.which(name)

# ====================== 2. Константы и Валидация ======================
QUALITY_SETTINGS = {
    "low":    {"render_size": 1024, "output_size": 512},
    "medium": {"render_size": 1536, "output_size": 768},
    "high":   {"render_size": 2048, "output_size": 1024},
}

DEFAULT_QUALITY = "low"
DEFAULT_COLORSCHEME = "Starnight"

KNOWN_COLORSCHEMES = {
    "cornfield", "metallic", "sunset", "starnight", "beforedawn",
    "nature", "deepocean", "solarized", "tomorrow", "tomorrow night", "monotone"
}

COLORSCHEME_BACKGROUNDS = {
    "starnight": (0, 0, 0),
    "tomorrow night": (0, 0, 0),
    "monotone": (0, 0, 0),
    "nature": (255, 255, 255),
}

# Белый список путей для директив include/use
ALLOWED_INCLUDE_PATTERNS = [
    re.compile(r"^BOSL2/[a-zA-Z0-9_\-\./]+\.scad$")
]

def validate_code(code: str) -> Tuple[bool, Optional[str]]:
    if not code or not code.strip():
        return False, "Отсутствует OpenSCAD код."
    if len(code) > settings.max_code_size:
        return False, f"Размер кода превышает лимит ({settings.max_code_size} символов)."

    # Поиск директив include <...> и use <...> с учетом возможных пробелов
    directives = re.findall(r"(?:^|\n)\s*(?:include|use)\s*<([^>]+)>", code)
    for target in directives:
        target = target.strip()
        # Предотвращение Path Traversal и чтения системных файлов
        if ".." in target or target.startswith("/") or target.startswith("\\"):
            return False, f"Недопустимый путь подключения файла: '{target}'."

        # Проверка соответствия белому списку разрешенных библиотек
        is_allowed = any(pattern.match(target) for pattern in ALLOWED_INCLUDE_PATTERNS)
        if not is_allowed:
            return False, f"Библиотека '{target}' запрещена. Разрешены только модули BOSL2 (например, BOSL2/std.scad)."

    return True, None

def get_image_base64(path: str) -> str:
    with open(path, "rb") as image_file:
        return base64.b64encode(image_file.read()).decode("utf-8")

# ====================== 3. Обработка Изображений ======================
def process_png_high_quality(png_path: str, target_size: int, colorscheme: str = DEFAULT_COLORSCHEME) -> bool:
    try:
        if not os.path.exists(png_path):
            return False
        img = Image.open(png_path).convert("RGBA")
        if img.width > target_size:
            img = img.resize((target_size, target_size), Image.LANCZOS)

        scheme_key = colorscheme.lower().strip()
        bg_color = COLORSCHEME_BACKGROUNDS.get(scheme_key)

        if bg_color is None:
            color_counts = Counter(item[:3] for item in img.getdata())
            bg_color = color_counts.most_common(1)[0][0]

        new_data = [
            (0, 0, 0, 0) if item[:3] == bg_color else item
            for item in img.getdata()
        ]

        img.putdata(new_data)
        img.save(png_path, "PNG", optimize=True)
        return True
    except Exception as e:
        logger.error(f"Pillow Error: {e}")
        return False

# ====================== 4. Выполнение OpenSCAD ======================
async def run_cmd_async(cmd: list, output_file: str, timeout: int = settings.openscad_timeout) -> bool:
    custom_env = os.environ.copy()
    existing_libpath = custom_env.get("OPENSCADPATH", "")
    
    # Добавляем каталог с BOSL2 в переменную окружения OPENSCADPATH
    custom_env["OPENSCADPATH"] = (
        f"{settings.libraries_dir}:{existing_libpath}".strip(":")
        if existing_libpath else settings.libraries_dir
    )

    process = await asyncio.create_subprocess_exec(
        *cmd,
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
        env=custom_env
    )

    try:
        stdout, stderr = await asyncio.wait_for(process.communicate(), timeout=timeout)
        stdout_txt = stdout.decode(errors="ignore")
        stderr_txt = stderr.decode(errors="ignore")
        full_log = (stdout_txt + "\n" + stderr_txt).strip()

        # Проверка на реальные ошибки компиляции или отсутствие файла
        if process.returncode != 0 or not os.path.exists(output_file) or "ERROR:" in full_log:
            errors_only = "\n".join([
                line for line in full_log.splitlines()
                if "ERROR:" in line or "WARNING:" in line or "line" in line.lower()
            ])
            raise OpenSCADError("Ошибка генерации модели", errors_only if errors_only else full_log)

        return True
    except asyncio.TimeoutError:
        try:
            process.kill()
        except Exception:
            pass
        raise OpenSCADError("Timeout: Рендеринг превысил лимит времени.")

async def run_openscad_async(args: list, output_file: str):
    openscad_bin = resolve_binary("openscad")
    if not openscad_bin:
        raise OpenSCADError("Исполняемый файл openscad не найден.")

    xvfb_bin = shutil.which("xvfb-run")
    if xvfb_bin:
        cmd = [xvfb_bin, "--auto-servernum", "--server-args=-screen 0 2048x2048x24", openscad_bin] + args
    else:
        cmd = [openscad_bin] + args

    return await run_cmd_async(cmd, output_file)

async def run_step_export_async(scad_path: str, output_path: str):
    scad2step_bin = resolve_binary("scad2step")
    if scad2step_bin:
        cmd = [scad2step_bin, scad_path, "-o", output_path]
        return await run_cmd_async(cmd, output_path)
    else:
        args = ["-o", output_path, scad_path]
        return await run_openscad_async(args, output_path)

async def render_task(
    openscad_code: str,
    mode: str = "stl",
    quality: str = DEFAULT_QUALITY,
    colorscheme: str = DEFAULT_COLORSCHEME,
    output_size: Optional[int] = None
) -> str:
    q = QUALITY_SETTINGS.get(quality, QUALITY_SETTINGS[DEFAULT_QUALITY])
    render_size = q["render_size"]
    final_size = output_size or q["output_size"]

    unique_id = str(uuid.uuid4())
    scad_path = os.path.join(settings.files_dir, f"{unique_id}.scad")
    output_path = os.path.join(settings.files_dir, f"{unique_id}.{mode}")

    with open(scad_path, "w", encoding="utf-8") as f:
        f.write(openscad_code)

    try:
        if mode == "step":
            await run_step_export_async(scad_path, output_path)
        elif mode == "stl":
            args = ["-o", output_path, scad_path]
            await run_openscad_async(args, output_path)
        else:  # png
            args = [
                "-o", output_path,
                f"--imgsize={render_size},{render_size}",
                "--projection=p",
                "--render",
                "--autocenter",
                "--viewall",
                f"--colorscheme={colorscheme}",
                scad_path
            ]
            await run_openscad_async(args, output_path)
            await asyncio.to_thread(process_png_high_quality, output_path, final_size, colorscheme)

        return output_path
    finally:
        if os.path.exists(scad_path):
            try:
                os.remove(scad_path)
            except Exception:
                pass

async def cleanup(file_paths: list[str], delay: int = 600):
    await asyncio.sleep(delay)
    for path in file_paths:
        if os.path.exists(path):
            try:
                os.remove(path)
            except Exception:
                pass

# ====================== 5. MCP Сервер ======================
app = FastAPI(title="OpenSCAD MCP Server with BOSL2")
mcp_server = Server("OpenSCAD-Renderer")
sse_transport = SseServerTransport("/messages")

OPENSCAD_CODE_TOOL_DESC = (
    "Код OpenSCAD для рендеринга. Доступна библиотека BOSL2 v2. "
    "Для ее использования подключайте: include <BOSL2/std.scad> в начале кода. "
    "Используйте attach(), diff(), cuboid(), cyl() вместо ресурсоемких булевых операций и minkowski()."
)

@mcp_server.list_tools()
async def handle_list_tools() -> List[Tool]:
    return [
        Tool(
            name="render_stl",
            description="Генерирует 3D-модель STL и возвращает URL для скачивания. Поддерживает BOSL2.",
            inputSchema={
                "type": "object",
                "properties": {"code": {"type": "string", "description": OPENSCAD_CODE_TOOL_DESC}},
                "required": ["code"]
            }
        ),
        Tool(
            name="render_step",
            description="Экспортирует CAD-модель в формате STEP (.step) и возвращает URL. Поддерживает BOSL2.",
            inputSchema={
                "type": "object",
                "properties": {"code": {"type": "string", "description": OPENSCAD_CODE_TOOL_DESC}},
                "required": ["code"]
            }
        ),
        Tool(
            name="render_png_url",
            description="Создает цветной PNG рендер модели и возвращает URL на изображение.",
            inputSchema={
                "type": "object",
                "properties": {
                    "code": {"type": "string", "description": OPENSCAD_CODE_TOOL_DESC},
                    "quality": {"type": "string", "enum": ["low", "medium", "high"], "default": DEFAULT_QUALITY},
                    "colorscheme": {"type": "string", "default": DEFAULT_COLORSCHEME}
                },
                "required": ["code"]
            }
        ),
        Tool(
            name="render_png_base64",
            description="Создает цветной PNG рендер и возвращает его прямо в сообщении (base64).",
            inputSchema={
                "type": "object",
                "properties": {
                    "code": {"type": "string", "description": OPENSCAD_CODE_TOOL_DESC},
                    "quality": {"type": "string", "enum": ["low", "medium", "high"], "default": DEFAULT_QUALITY},
                    "colorscheme": {"type": "string", "default": DEFAULT_COLORSCHEME}
                },
                "required": ["code"]
            }
        )
    ]

@mcp_server.call_tool()
async def handle_call_tool(name: str, arguments: dict) -> List[Union[TextContent, ImageContent]]:
    code = arguments.get("code", "")
    is_valid, err = validate_code(code)
    if not is_valid:
        return [TextContent(type="text", text=f"❌ Ошибка валидации: {err}")]

    raw_quality = arguments.get("quality", DEFAULT_QUALITY)
    quality = raw_quality if raw_quality in QUALITY_SETTINGS else DEFAULT_QUALITY
    
    raw_scheme = arguments.get("colorscheme", DEFAULT_COLORSCHEME)
    colorscheme = raw_scheme if raw_scheme.lower() in KNOWN_COLORSCHEMES else DEFAULT_COLORSCHEME

    try:
        if name == "render_stl":
            path = await render_task(code, mode="stl")
            url = f"{settings.base_url}/files/{os.path.basename(path)}"
            asyncio.create_task(cleanup([path], delay=600))
            return [TextContent(type="text", text=f"✅ STL готов:\n{url}")]

        elif name == "render_step":
            path = await render_task(code, mode="step")
            url = f"{settings.base_url}/files/{os.path.basename(path)}"
            asyncio.create_task(cleanup([path], delay=600))
            return [TextContent(type="text", text=f"✅ STEP готов:\n{url}")]

        elif name == "render_png_url":
            path = await render_task(code, mode="png", quality=quality, colorscheme=colorscheme)
            url = f"{settings.base_url}/files/{os.path.basename(path)}"
            asyncio.create_task(cleanup([path], delay=600))
            return [TextContent(type="text", text=f"✅ PNG готов:\n{url}")]

        elif name == "render_png_base64":
            path = await render_task(code, mode="png", quality=quality, colorscheme=colorscheme)
            b64_data = await asyncio.to_thread(get_image_base64, path)
            asyncio.create_task(cleanup([path], delay=300))
            return [
                ImageContent(type="image", data=b64_data, mimeType="image/png"),
                TextContent(type="text", text="✅ Модель успешно отрендерена.")
            ]
    except OpenSCADError as e:
        return [TextContent(type="text", text=f"❌ Ошибка компиляции OpenSCAD:\n{e.stderr}")]
    except Exception as e:
        logger.error(f"Error executing {name}: {e}")
        return [TextContent(type="text", text=f"❌ Внутренняя ошибка: {str(e)}")]

# ====================== 6. HTTP API ======================
@app.post("/generate_transparent_png")
async def api_generate_transparent_png(
    background_tasks: BackgroundTasks,
    code: str = Form(...),
    quality: str = Form(DEFAULT_QUALITY),
    colorscheme: str = Form(DEFAULT_COLORSCHEME),
    size: Optional[int] = Form(None)
):
    is_valid, err = validate_code(code)
    if not is_valid:
        raise HTTPException(status_code=400, detail=err)
    try:
        path = await render_task(code, mode="png", quality=quality, colorscheme=colorscheme, output_size=size)
        background_tasks.add_task(cleanup, [path], delay=60)
        return FileResponse(path, media_type="image/png")
    except OpenSCADError as e:
        raise HTTPException(status_code=422, detail=e.stderr)

@app.post("/generate_stl")
async def api_generate_stl(background_tasks: BackgroundTasks, code: str = Form(...)):
    is_valid, err = validate_code(code)
    if not is_valid:
        raise HTTPException(status_code=400, detail=err)
    try:
        path = await render_task(code, mode="stl")
        background_tasks.add_task(cleanup, [path], delay=60)
        return FileResponse(path, media_type="model/stl", filename="model.stl")
    except OpenSCADError as e:
        raise HTTPException(status_code=422, detail=e.stderr)

@app.post("/generate_step")
async def api_generate_step(background_tasks: BackgroundTasks, code: str = Form(...)):
    is_valid, err = validate_code(code)
    if not is_valid:
        raise HTTPException(status_code=400, detail=err)
    try:
        path = await render_task(code, mode="step")
        background_tasks.add_task(cleanup, [path], delay=60)
        return FileResponse(path, media_type="application/step", filename="model.step")
    except OpenSCADError as e:
        raise HTTPException(status_code=422, detail=e.stderr)

@app.get("/files/{filename}")
async def get_file(filename: str):
    path = os.path.abspath(os.path.join(settings.files_dir, filename))
    # только файлы внутри files_dir (uuid-имена рендеров): отсекаем ../ и прочее
    root = os.path.abspath(settings.files_dir)
    if not path.startswith(root + os.sep) or not os.path.isfile(path):
        raise HTTPException(status_code=404, detail="Файл не найден")
    return FileResponse(path)

# ====================== 7. MCP SSE Transport ======================
@app.get("/sse")
async def handle_sse(request: Request):
    async with sse_transport.connect_sse(request.scope, request.receive, request._send) as (read, write):
        await mcp_server.run(read, write, mcp_server.create_initialization_options())

@app.post("/messages")
async def handle_messages(request: Request):
    await sse_transport.handle_post_message(request.scope, request.receive, request._send)

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host=settings.host, port=settings.port)
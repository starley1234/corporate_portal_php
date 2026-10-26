// =============================================================================
// core/math.scad — векторная математика. Про изделие здесь ничего нет.
//
// Откуда: source_data/for_3d_graph.scad (наработки владельца, 12.10.2026),
// зона «топологическая математика». Кластеризация, сигнатуры и спецификация
// оттуда же убраны: их считает Service_Nj667_Build на PHP, а сюда прилетает
// готовый расклад (CL, CTYPE, UNIQ) — иначе BOM на сайте и STL в OpenSCAD
// разъедутся.
//
// Склейка файла: PHP (generators нет, этот файл идёт третьим по счёту).
// Имена переменных не повторять: повторное присваивание в OpenSCAD — ошибка.
// =============================================================================

eps = 1e-6;

// Нормаль, безопасная на нулевом векторе: узел без связи не должен валить CSG.
function unit(v) = let(n = norm(v)) n < eps ? [0, 0, 1] : v / n;

function vsum(v, k = 0) = k >= len(v) ? [0, 0, 0] : v[k] + vsum(v, k + 1);
function centroid(ix)  = vsum([ for (i = ix) NODES[i] ]) / len(ix);

// Крен паза зависит от НАПРАВЛЕНИЯ РЕБРА: edge_up(d) == edge_up(-d), иначе
// симметричная конструкция получает два разных узла там, где они одинаковы.
function edge_up(d) =
    let(z = unit(d), u = [0, 0, 1])
        abs(z * u) > 0.999
            ? [1, 0, 0]
            : let(p = u - (u * z) * z) p / norm(p);

// Поставить детей так, чтобы ось Z совпала с dir, а «верх» сечения — с up.
module orient_up(dir, up) {
    zc = dir / norm(dir);
    y0 = up - (up * zc) * zc;
    yc = norm(y0) < eps ? (abs(zc[0]) < 0.9 ? [1, 0, 0] : [0, 1, 0]) : y0 / norm(y0);
    xc = cross(yc, zc);
    multmatrix([
        [xc[0], yc[0], zc[0], 0],
        [xc[1], yc[1], zc[1], 0],
        [xc[2], yc[2], zc[2], 0],
        [0,     0,     0,     1]
    ]) children();
}

// ------------------------------------------------------------------ граф
// Лучи узла i: [направление, внутренний?]. Внутренний — тот, что ведёт в узел
// того же кластера: его паз резать нельзя, деталь-кластер уже целая.
function arms_of(i) = [ for (e = EDGES)
    if (e[0] == i || e[1] == i)
        let(o = (e[0] == i ? e[1] : e[0]))
            [ NODES[o] - NODES[i], CL[o] == CL[i] ] ];

function cluster_nodes(r)   = [ for (i = [0 : len(NODES) - 1]) if (CL[i] == r) i ];
function edge_internal(e)   = CL[e[0]] == CL[e[1]];
function edge_external(e)   = CL[e[0]] != CL[e[1]];
function free_span(e)       = norm(NODES[e[1]] - NODES[e[0]]) - 2 * r_in;

// Габарит кластера с учётом посадочных мест — шаг раскладки деталей на столе.
function cl_span(r)     = let(c = cluster_nodes(r))
    (len(c) < 2 ? 0 : max([ for (a = c) for (b = c) norm(NODES[a] - NODES[b]) ]));
function cl_size(r)     = cl_span(r) + core_size + 2 * stick_insert_depth;

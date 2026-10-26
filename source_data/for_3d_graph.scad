/*
из пятиугольников геодезический купол

координаты не параметризуй

Это 1V-геокупол (грани — правильные треугольники, вершины лежат в пятиугольных «розетках» по 5 рёбер — пятиугольная симметрия сохранена). Пятиугольники здесь — это контуры вокруг каждой 5-реберной вершины; замкнуть купол только из плоских пятиугольников нельзя (Эйлер), поэтому дан икосаэдрический каркас с единой длиной ребра 105 мм — оптимально для сборки.
*/


NODES = [
  // ===== Икосаэдр (норм. радиус R=100), нижняя полусфера + пояс =====
  // Углы додекаэдра/икосаэдра масштабированы к R=100, координаты целые (мм).

  // --- Верхняя вершина (полюс) ---
  [   0,    0,  100],  // 0

  // --- Верхнее кольцо (5 вершин), Z=+45 ---
  [  89,    0,   45],  // 1
  [  28,   85,   45],  // 2
  [ -72,   53,   45],  // 3
  [ -72,  -53,   45],  // 4
  [  28,  -85,   45],  // 5

  // --- Нижнее кольцо (5 вершин), Z=-45 ---
  [  72,   53,  -45],  // 6
  [ -28,   85,  -45],  // 7
  [ -89,    0,  -45],  // 8
  [ -28,  -85,  -45],  // 9
  [  72,  -53,  -45]   // 10
];

EDGES = [
  // --- полюс → верхнее кольцо (5) ---
  [0,1],[0,2],[0,3],[0,4],[0,5],

  // --- верхнее кольцо между собой (5) ---
  [1,2],[2,3],[3,4],[4,5],[5,1],

  // --- зигзаг верх↔низ (10) ---
  [1,6],[6,2],[2,7],[7,3],[3,8],[8,4],[4,9],[9,5],[5,10],[10,1],

  // --- нижнее кольцо между собой (5) ---
  [6,7],[7,8],[8,9],[9,10],[10,6]
];

// ============================================================
//                        РЕЖИМ ВЫВОДА
// ============================================================
// "assembly" — весь дом в сборе (для наглядности)
// "parts"    — все уникальные детали разложены на столе (сетка)
// "single"   — печать ОДНОЙ детали по индексу (part_index)
mode        = "parts";   // "assembly" | "parts" | "single"
part_index  = 0;            // какой уникальный тип детали печатать в режиме "single"
show_sticks = true;
show_labels = false;

// ============================================================
//                   ПАРАМЕТРЫ ДЕТАЛЕЙ
// ============================================================
// ТИП ПАЛОЧЕК / ВАЛА:
//   "flat"  = палочки от мороженого (прямоугольное сечение)
//   "round" = палочки с круглым сечением
//   "cross" = стандартный крестовой вал (крестообразное сечение)
stick_type         = "cross";

// --- плоская палочка (от мороженого) ---
stick_width        = 10;
stick_thickness    = 2.2;

// --- круглое сечение ---
stick_diameter     = 6;

// --- крестовой вал ---
cross_size         = 3.5;     // габарит креста (размах рёбер)
cross_thick        = 1.3;   // толщина каждого ребра креста

stick_insert_depth = 7;
tolerance          = 0.25;
wall_thickness     = 1.0;
perforation        = false;
$fn = 40;

min_angle_warn = 22;
min_free_span  = 5;

// --- Управление объединением узлов ---
merge_nodes = true;   // объединять пересекающиеся узлы в единый объект
merge_gap   = 0.5;    // доп. запас к порогу пересечения, мм

// --- габаритное сечение (bounding box) в зависимости от типа ---
stick_bb_w = stick_type=="flat"  ? stick_width
           : stick_type=="round" ? stick_diameter
           :                        cross_size;
stick_bb_t = stick_type=="flat"  ? stick_thickness
           : stick_type=="round" ? stick_diameter
           :                        cross_size;

slot_w   = stick_bb_w + tolerance;
slot_t   = stick_bb_t + tolerance;
outer_w  = slot_w + wall_thickness*2;
outer_t  = slot_t + wall_thickness*2;
stop_thickness = 2.0;
edge_r   = 1.0;
core_size= max(outer_w, outer_t);
r_in     = core_size/2 + stop_thickness;
eps      = 1e-6;

POS = NODES;
N   = len(POS);

merge_dist = merge_nodes ? (2*r_in + merge_gap) : 0;   // порог "пересечения" узлов

function valid_edge(e) = e[0]>=0 && e[0]<N && e[1]>=0 && e[1]<N && e[0]!=e[1];

function dirs_of(i) = [ for(e=EDGES)
    if(valid_edge(e) && (e[0]==i || e[1]==i))
        (e[0]==i ? (POS[e[1]]-POS[i]) : (POS[e[0]]-POS[i])) ];

// крен паза зависит от НАПРАВЛЕНИЯ РЕБРА (edge_up(d)==edge_up(-d))
function edge_up(d) =
    let(z = d/norm(d), u = [0,0,1])
    abs(z*u) > 0.999
        ? [1,0,0]
        : let(p = u - (u*z)*z) p/norm(p);

// ============================================================
//        КЛАСТЕРИЗАЦИЯ ПЕРЕСЕКАЮЩИХСЯ УЗЛОВ
// ============================================================
function merge_link(i,j) = merge_dist>0 && norm(POS[i]-POS[j]) < merge_dist;
function relax(lab) = [ for(i=[0:N-1])
    min(concat([lab[i]], [ for(j=[0:N-1]) if(i!=j && merge_link(i,j)) lab[j] ])) ];
function fixpoint(lab,k) = k<=0 ? lab : fixpoint(relax(lab), k-1);

CLABELS = (merge_nodes && N>0) ? fixpoint([for(i=[0:N-1]) i], N) : [for(i=[0:N-1]) i];

function first_i(x,list,k=0) = k>=len(list) ? -1 : (list[k]==x ? k : first_i(x,list,k+1));
function distinct(v) = [ for(i=[0:len(v)-1]) if(first_i(v[i],v)==i) v[i] ];

CL_REPS = distinct(CLABELS);                       // метка-представитель каждого кластера
function cluster_nodes(r)   = [ for(i=[0:N-1]) if(CLABELS[i]==r) i ];
function edge_internal(e)   = valid_edge(e) && CLABELS[e[0]]==CLABELS[e[1]];
function edge_external(e)   = valid_edge(e) && CLABELS[e[0]]!=CLABELS[e[1]];

// лучи узла: [направление, внутренний?]
function node_arms(i) = [ for(e=EDGES)
    if(valid_edge(e) && (e[0]==i || e[1]==i))
        let(o = (e[0]==i ? e[1] : e[0]))
        [ POS[o]-POS[i], CLABELS[o]==CLABELS[i] ] ];

// ============================================================
//        СИГНАТУРЫ УГЛОВ УЗЛА (для дедупликации типов)
// ============================================================
function ang(a,b) = acos(max(-1,min(1,(a*b)/(norm(a)*norm(b)))));
function qsort(v) = len(v)<=1 ? v :
    let(p=v[0], t=[for(i=[1:len(v)-1]) v[i]])
    concat(qsort([for(x=t) if(x<p) x]), [p], qsort([for(x=t) if(x>=p) x]));
function rev(v) = [ for(i=[len(v)-1:-1:0]) v[i] ];
function count_val(x,v)= len([for(y=v) if(y==x) 1]);

function signature(i) =
    let(d=dirs_of(i), n=len(d),
        A = n<2 ? [] : [ for(a=[0:n-2]) for(b=[a+1:n-1]) round(ang(d[a],d[b])*2)/2 ])
    concat([n], qsort(A));
SIGS = [ for(i=[0:N-1]) signature(i) ];
function min_pair_angle(i) = let(d=dirs_of(i), n=len(d))
    n<2 ? 999 : min([for(a=[0:n-2]) for(b=[a+1:n-1]) ang(d[a],d[b])]);

// ============================================================
//     СИГНАТУРЫ И ТИПЫ КЛАСТЕРОВ (единых деталей)
// ============================================================
function vsum(v,k=0)   = k>=len(v) ? [0,0,0] : v[k]+vsum(v,k+1);
function cl_centroid(c)= vsum([for(i=c) POS[i]]) / len(c);
function cl_span(r)    = let(c=cluster_nodes(r))
    (len(c)<2 ? 0 : max([for(a=c) for(b=c) norm(POS[a]-POS[b])]));
function cl_size(r)    = cl_span(r) + core_size + 2*stick_insert_depth;

function cluster_intlens(r) = qsort([ for(e=EDGES)
    if(valid_edge(e) && CLABELS[e[0]]==r && CLABELS[e[1]]==r && e[0]<e[1])
        round(norm(POS[e[0]]-POS[e[1]])*10)/10 ]);
function cluster_sig(r) = str( len(cluster_nodes(r)), "N",
    qsort([ for(i=cluster_nodes(r)) str(SIGS[i]) ]), "L", cluster_intlens(r) );

CSIGS      = [ for(r=CL_REPS) cluster_sig(r) ];
CTYPE_REPS = [ for(k=[0:len(CL_REPS)-1]) if(first_i(CSIGS[k],CSIGS)==k) CL_REPS[k] ];
function ctype_idx_of_rep(r) = first_i(cluster_sig(r), [for(tr=CTYPE_REPS) cluster_sig(tr)]);
function ctype_count(tr)     = len([for(r=CL_REPS) if(cluster_sig(r)==cluster_sig(tr)) 1]);
function cluster_ext_arms(r) = len([for(e=EDGES)
    if(valid_edge(e) && ((CLABELS[e[0]]==r) != (CLABELS[e[1]]==r))) 1]);

// ============================================================
//              ЛИСТ РАСКРОЯ ПАЛОЧЕК (только внешние рёбра)
// ============================================================
ELEN = [ for(e=EDGES) if(edge_external(e))
    round((norm(POS[e[0]]-POS[e[1]]) - 2*r_in + 2*stick_insert_depth)*10)/10 ];
CUTS = len(ELEN)==0 ? [] : rev(qsort(distinct(ELEN)));

// ============================================================
//                   СПЕЦИФИКАЦИЯ (BOM) В КОНСОЛЬ
// ============================================================
function _acc(v,i,s) = i>=len(v) ? s : _acc(v,i+1,s+v[i]);
function _sumlen() = _acc(ELEN,0,0);

function stick_type_name() =
      stick_type=="flat"  ? "плоские (от мороженого)"
    : stick_type=="round" ? "круглое сечение"
    : stick_type=="cross" ? "крестовой вал"
    :                        stick_type;
function stick_type_dims() =
      stick_type=="flat"  ? str(stick_width, " x ", stick_thickness, " мм")
    : stick_type=="round" ? str("Ø", stick_diameter, " мм")
    : stick_type=="cross" ? str("крест ", cross_size, " мм, ребро ", cross_thick, " мм")
    :                        "";

echo(str("=========== СПЕЦИФИКАЦИЯ ==========="));
echo(str("Тип палочек/вала: ", stick_type_name(), " [", stick_type_dims(), "]"));
echo(str("Узлов: ", N,
         " | Деталей(кластеров): ", len(CL_REPS),
         " | Уникальных типов деталей: ", len(CTYPE_REPS),
         " | Объединено узлов: ", N-len(CL_REPS)));

echo("--- ДЕТАЛИ ДЛЯ ПЕЧАТИ (кластеры) ---");
for (t=[0:len(CTYPE_REPS)-1]) {
    tr = CTYPE_REPS[t]; c = cluster_nodes(tr);
    echo(str("  Тип #", t, ": ", ctype_count(tr), " шт. | узлов в детали: ", len(c),
             " | внешних лучей (пазов): ", cluster_ext_arms(tr)));
}
echo(str("  ИТОГО деталей напечатать: ", len(CL_REPS),
         " шт. (", len(CTYPE_REPS), " уникальных)"));

echo("--- ПАЛОЧКИ (лист раскроя) ---");
for (c=CUTS)
    echo(str("  ", c, " мм  x ", count_val(c,ELEN), " шт."));
echo(str("  ИТОГО палочек: ", len(ELEN),
         " | суммарная длина: ", round(_sumlen()/10)/100, " м"));

// ============================================================
//                   ПРОВЕРКА СОБИРАЕМОСТИ
// ============================================================
function ekey(e) = e[0]<e[1] ? str(e[0],"-",e[1]) : str(e[1],"-",e[0]);
EKEYS = [ for(e=EDGES) ekey(e) ];

n_badidx = len([for(e=EDGES) if(e[0]<0||e[0]>=N||e[1]<0||e[1]>=N) 1]);
n_loops  = len([for(e=EDGES) if(e[0]==e[1]) 1]);
n_dup    = len([for(j=[0:len(EDGES)-1]) if(first_i(EKEYS[j],EKEYS)!=j) 1]);
n_narrow = len([for(i=[0:N-1]) if(min_pair_angle(i)<min_angle_warn) 1]);
n_short  = len([for(e=EDGES) if(edge_external(e) && (norm(POS[e[0]]-POS[e[1]])-2*r_in)<min_free_span) 1]);
issues   = n_badidx+n_loops+n_dup+n_narrow+n_short;

echo("--- ОБЪЕДИНЕНИЕ УЗЛОВ ---");
if (N==len(CL_REPS))
    echo("  Пересечений нет — каждый узел печатается отдельным коннектором.");
else
    for (r=CL_REPS) {
        c = cluster_nodes(r);
        if (len(c)>1)
            echo(str("  Узлы ", c, " пересекаются -> печатаются как ЕДИНЫЙ объект."));
    }

echo("--- ПРОВЕРКА СОБИРАЕМОСТИ ---");
for (j=[0:len(EDGES)-1]) {
    e=EDGES[j];
    if (e[0]<0||e[0]>=N||e[1]<0||e[1]>=N)
        echo(str("  [ОШИБКА] ребро #",j," ссылается на несуществующий узел: ",e));
    if (e[0]==e[1])
        echo(str("  [ОШИБКА] ребро #",j," — петля на узел ",e[0]));
    if (first_i(EKEYS[j],EKEYS)!=j)
        echo(str("  [ВНИМАНИЕ] ребро #",j," дублирует другое: ",e));
}
for (i=[0:N-1]) {
    ma = min_pair_angle(i);
    if (ma < min_angle_warn)
        echo(str("  [ВНИМАНИЕ] узел ",i,": мин. угол между лучами ",round(ma*10)/10,
                 "° < ",min_angle_warn,"°"));
}
for (j=[0:len(EDGES)-1]) {
    e=EDGES[j];
    if (edge_external(e)) {
        free = norm(POS[e[0]]-POS[e[1]]) - 2*r_in;
        if (free < min_free_span)
            echo(str("  [ВНИМАНИЕ] палочка #",j," (узлы ",e[0],"-",e[1],
                     "): свободный пролёт ",round(free*10)/10," мм < ",min_free_span," мм"));
    }
}
echo(issues==0 ? "  OK — проблем не найдено."
               : str("  Найдено замечаний: ", issues, " (см. выше)."));

// ============================================================
//                          ВЫВОД
// ============================================================
PAL = ["SteelBlue","IndianRed","DarkSeaGreen","Goldenrod","MediumPurple","Teal","Salmon","SlateGray"];
if      (mode=="parts")  print_parts();
else if (mode=="single") print_single(part_index);
else                     assembly();

module assembly() {
    for (r=CL_REPS)
        color(PAL[ctype_idx_of_rep(r) % len(PAL)])
            part_abs(r);
    if (show_sticks)
        color("BurlyWood")
        for (e=EDGES) if (edge_external(e)) {
            a=POS[e[0]]; b=POS[e[1]]; L=max(2,norm(b-a)-2*r_in);
            dir=(b-a)/norm(b-a);
            translate((a+b)/2) orient_up(dir, edge_up(dir))
                linear_extrude(L, center=true) stick_profile();
        }
    if (show_labels)
        for (i=[0:N-1])
            color("black")
                translate(POS[i]+[0,0,core_size*1.1])
                    rotate([90,0,0])
                        linear_extrude(0.6)
                            text(str(ctype_idx_of_rep(CLABELS[i])), size=7,
                                 halign="center", valign="center");
}

// Единая деталь-кластер на своих реальных координатах
module part_abs(r) {
    union() {
        for (i=cluster_nodes(r))
            translate(POS[i]) connector(node_arms(i));
        // сплошные балки для гарантированного сращивания внутренних рёбер
        for (e=EDGES)
            if (valid_edge(e) && CLABELS[e[0]]==r && CLABELS[e[1]]==r && e[0]<e[1])
                join_beam(POS[e[0]], POS[e[1]]);
    }
}

module join_beam(a,b) {
    d=b-a; L=norm(d);
    if (L>eps)
        translate((a+b)/2) orient_up(d/L, edge_up(d/L))
            linear_extrude(L, center=true) outer_profile();
}

// Одна деталь, поставленная на стол (для печати/экспорта по отдельности)
module part_on_bed(tr) {
    c = cluster_nodes(tr);
    if (len(c)==1)
        // одиночный узел — кладём плашмя
        translate([0,0,core_size])
            orient_up([0,0,-1],[1,0,0])
                connector(node_arms(c[0]));
    else {
        // кластер — центрируем в XY и ставим нижней гранью на стол
        ctr = cl_centroid(c);
        zb  = min([for(i=c) POS[i][2]]) - (r_in + stick_insert_depth);
        translate([-ctr[0], -ctr[1], -zb])
            part_abs(tr);
    }
}

// Режим: печать ОДНОЙ детали по индексу
module print_single(idx) {
    T = len(CTYPE_REPS);
    if (T==0)
        echo("Нет деталей для печати.");
    else {
        i  = (idx % T + T) % T;   // безопасный индекс (по кругу)
        tr = CTYPE_REPS[i];
        echo(str("ПЕЧАТЬ ОДНОЙ ДЕТАЛИ: тип #", i, " из ", T,
                 " | нужно ", ctype_count(tr), " шт. такой детали."));
        color(PAL[i % len(PAL)])
            part_on_bed(tr);
    }
}

// Режим: все уникальные детали разложены на столе
module print_parts() {
    T    = len(CTYPE_REPS);
    cols = max(1, ceil(sqrt(T)));
    step = (T==0?0:max([for(tr=CTYPE_REPS) cl_size(tr)])) + 20;
    for (t=[0:T-1]) {
        tr  = CTYPE_REPS[t];
        col = t % cols; row = floor(t/cols);
        translate([col*step, -row*step, 0])
            color(PAL[t % len(PAL)])
                part_on_bed(tr);
    }
    echo(str("Раскладка печати: ", T, " уникальных деталей, сетка ",
             cols, " x ", ceil(T/cols), ", шаг ", step, " мм."));
}

// ============================================================
//          КОННЕКТОР + ГЕОМЕТРИЯ ЛУЧА
//   arm = [направление, внутренний?].  Паз режется только у внешних.
// ============================================================
module connector(arms) {
    difference() {
        union() {
            hull() for (a=arms) orient_up(a[0], edge_up(a[0])) arm_geometry("core");
            for (a=arms)        orient_up(a[0], edge_up(a[0])) arm_geometry("body");
        }
        for (a=arms) if (!a[1]) orient_up(a[0], edge_up(a[0])) arm_geometry("slots");
        if (perforation) for (a=arms) if (!a[1]) orient_up(a[0], edge_up(a[0])) window_cut();
        sphere(d=min(slot_w,slot_t));
    }
}
module orient_up(dir, up) {
    zc=dir/norm(dir); y0=up-(up*zc)*zc;
    yc = norm(y0)<eps ? (abs(zc[0])<0.9?[1,0,0]:[0,1,0]) : y0/norm(y0);
    xc = cross(yc,zc);
    multmatrix([[xc[0],yc[0],zc[0],0],[xc[1],yc[1],zc[1],0],[xc[2],yc[2],zc[2],0],[0,0,0,1]])
        children();
}

// ---- 2D-профили сечения в зависимости от типа палочки/вала ----
module cross_2d(size, th) {
    union() {
        square([size, th], center=true);
        square([th, size], center=true);
    }
}
// профиль реальной палочки (для визуализации сборки)
module stick_profile() {
    if (stick_type=="flat")
        square([stick_width, stick_thickness], center=true);
    else if (stick_type=="round")
        circle(d = stick_diameter);
    else
        cross_2d(cross_size, cross_thick);
}
// профиль паза (отверстие под палочку с допуском)
module slot_profile() {
    if (stick_type=="flat")
        square([slot_w, slot_t], center=true);
    else if (stick_type=="round")
        circle(d = stick_diameter + tolerance);
    else
        cross_2d(cross_size + tolerance, cross_thick + tolerance);
}
// профиль внешней стенки луча (паз + стенка)
module outer_profile() {
    if (stick_type=="flat")
        offset(edge_r) square([outer_w-edge_r*2, outer_t-edge_r*2], center=true);
    else if (stick_type=="round")
        circle(d = stick_diameter + tolerance + wall_thickness*2);
    else
        offset(wall_thickness) cross_2d(cross_size + tolerance, cross_thick + tolerance);
}

module arm_geometry(m) {
    if (m=="body")
        translate([0,0,core_size/2-0.1])
            linear_extrude(stick_insert_depth+stop_thickness)
                outer_profile();
    else if (m=="core")
        linear_extrude(core_size/2)
            outer_profile();
    else if (m=="slots")
        translate([0,0,core_size/2+stop_thickness]) {
            translate([0,0,stick_insert_depth/2+0.1])
                linear_extrude(stick_insert_depth+0.2, center=true)
                    slot_profile();
            translate([0,0,stick_insert_depth-0.5])
                linear_extrude(2, scale=[1.15,1.25])
                    slot_profile();
        }
}
module window_cut() {
    translate([0,0,core_size/2+stop_thickness+stick_insert_depth/2])
        cube([slot_w*0.7, outer_t+5, stick_insert_depth*0.5], center=true);
}
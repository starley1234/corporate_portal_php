// =============================================================================
// generators/assembly.scad — режимы вывода.
//
//   mode = "assembly" — вся конструкция на своих координатах (для просмотра
//                       и для «склейки на столе»);
//   mode = "parts"    — все уникальные детали разложены сеткой (один STL,
//                       печатать сколько нужно копий);
//   mode = "single"   — одна уникальная деталь по part_index: этим режимом
//                       сайт собирает «узлы к печати» (по STL на каждый тип)
//                       и тот же .scad отдаёт человеку.
//
// Данные (NODES в мм, EDGES, CL, CTYPE, UNIQ) пишет PHP — см.
// Service_Nj667_Build::scad(). Никакой математики графа и никакой
// спецификации здесь нет: BOM считает сайт, иначе цифры разъедутся.
// =============================================================================

PAL = ["SteelBlue", "IndianRed", "DarkSeaGreen", "Goldenrod",
       "MediumPurple", "Teal", "Salmon", "SlateGray"];

if (mode == "parts")
    print_parts();
else if (mode == "single")
    print_single(part_index);
else
    assembly();

// Сборка: каждый кластер — своей деталью на реальных координатах, палочки —
// между кластерами (внутри кластера пазов нет, балка join_beam уже срослась).
module assembly() {
    for (r = [0 : len(CTYPE) - 1])
        color(PAL[CTYPE[r] % len(PAL)])
            part_abs(r);

    if (show_sticks)
        color("BurlyWood")
            for (e = EDGES)
                if (edge_external(e))
                    stick_between(NODES[e[0]], NODES[e[1]]);
}

// Единая деталь-кластер на своих координатах.
module part_abs(r) {
    union() {
        for (i = cluster_nodes(r))
            translate(NODES[i])
                connector(arms_of(i));

        for (e = EDGES)
            if (CL[e[0]] == r && CL[e[1]] == r && e[0] < e[1])
                join_beam(NODES[e[0]], NODES[e[1]]);
    }
}

// Деталь плашмя, нижней гранью на стол — так её отдавать в слайсер.
// Одиночный узел разворачиваем «лежа»: печатать его надо без поддержек.
module part_on_bed(t) {
    c = cluster_nodes(UNIQ[t]);

    if (len(c) == 1)
        translate([0, 0, core_size])
            orient_up([0, 0, -1], [1, 0, 0])
                connector(arms_of(c[0]));
    else {
        ctr = centroid(c);
        zb  = min([ for (i = c) NODES[i][2] ]) - (r_in + stick_insert_depth);
        translate([-ctr[0], -ctr[1], -zb])
            part_abs(UNIQ[t]);
    }
}

// Все уникальные типы деталей — сеткой на столе (один STL).
module print_parts() {
    T    = len(UNIQ);
    cols = max(1, ceil(sqrt(T)));
    step = (T == 0 ? 0 : max([ for (t = [0 : T - 1]) cl_size(UNIQ[t]) ])) + 20;

    for (t = [0 : T - 1]) {
        col = t % cols;
        row = floor(t / cols);
        translate([col * step, -row * step, 0])
            color(PAL[t % len(PAL)])
                part_on_bed(t);
    }
}

// Одна деталь по индексу уникального типа (индекс — по кругу, чтобы левый
// part_index не ронял рендер).
module print_single(idx) {
    T = len(UNIQ);

    if (T > 0) {
        i = ((idx % T) + T) % T;
        color(PAL[i % len(PAL)])
            part_on_bed(i);
    }
}

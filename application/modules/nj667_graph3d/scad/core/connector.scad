// =============================================================================
// core/connector.scad — узел-коннектор: корпус, лучи, пазы.
//
// Зона «конструктив» из source_data/for_3d_graph.scad. Профиль сечения —
// НЕ отсюда: его даёт подключённый node_types/<тип>.scad (bb_w, bb_t,
// stick_profile, slot_profile, outer_profile). Поэтому файл работает с любым
// типом узла и ничего про палочки не знает.
//
// Порядок склейки: параметры → тип узла → ЭТОТ файл → генератор → данные.
// =============================================================================

// --- размеры паза и стенки от габарита сечения (bb_w/bb_t из файла типа) ----
slot_w  = bb_w + tolerance;
slot_t  = bb_t + tolerance;
outer_w = slot_w + wall_thickness * 2;
outer_t = slot_t + wall_thickness * 2;

// Ядро узла: всё, что общее у лучей. Радиус «юбки», до которой палочка входит.
core_size = max(outer_w, outer_t);
r_in      = core_size / 2 + stop_thickness;

// 2D-крест (нужен типам cross и тем, кто его наследует).
module cross_2d(size, th) {
    union() {
        square([size, th], center = true);
        square([th, size], center = true);
    }
}

// Луч: m = "core" (нижняя юбка), "body" (тело), "slots" (паз + фаска).
module arm_geometry(m) {
    if (m == "body")
        translate([0, 0, core_size / 2 - 0.1])
            linear_extrude(stick_insert_depth + stop_thickness)
                outer_profile();
    else if (m == "core")
        linear_extrude(core_size / 2)
            outer_profile();
    else if (m == "slots")
        translate([0, 0, core_size / 2 + stop_thickness]) {
            translate([0, 0, stick_insert_depth / 2 + 0.1])
                linear_extrude(stick_insert_depth + 0.2, center = true)
                    slot_profile();
            // Слегка расширенная фаска в конце паза: палочку легче наживить.
            translate([0, 0, stick_insert_depth - 0.5])
                linear_extrude(2, scale = [1.15, 1.25])
                    slot_profile();
        }
}

// Окна в стенке луча — экономия пластика и вид на посадку. Только по желанию.
module window_cut() {
    translate([0, 0, core_size / 2 + stop_thickness + stick_insert_depth / 2])
        cube([slot_w * 0.7, outer_t + 5, stick_insert_depth * 0.5], center = true);
}

// Один узел (или один узел кластера — ему все лучи по назначению).
module connector(arms) {
    difference() {
        union() {
            hull() for (a = arms) orient_up(a[0], edge_up(a[0])) arm_geometry("core");
            for (a = arms)        orient_up(a[0], edge_up(a[0])) arm_geometry("body");
        }
        for (a = arms) if (!a[1]) orient_up(a[0], edge_up(a[0])) arm_geometry("slots");
        if (perforation)
            for (a = arms) if (!a[1]) orient_up(a[0], edge_up(a[0])) window_cut();
        // Сферическая выборка в центре: лучи сходятся под любым углом, и узел
        // не должен оставлять лишний материал от hull() внутри себя.
        sphere(d = min(slot_w, slot_t));
    }
}

// Сплошная балка между двумя узлами одного кластера: гарантирует, что
// объединённый узел — одно тело, а не касание двух оболочек.
module join_beam(a, b) {
    d = b - a;
    L = norm(d);
    if (L > eps)
        translate((a + b) / 2) orient_up(d / L, edge_up(d / L))
            linear_extrude(L, center = true)
                outer_profile();
}

// Палочка между кластерами: от поверхности узла до поверхности узла.
module stick_between(a, b) {
    d = b - a;
    L = max(2, norm(d) - 2 * r_in);
    translate((a + b) / 2) orient_up(d / norm(d), edge_up(d / norm(d)))
        linear_extrude(L, center = true)
            stick_profile();
}

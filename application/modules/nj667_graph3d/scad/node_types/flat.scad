// =============================================================================
// node_types/flat.scad — плоская палочка (от мороженого).
//
// Контракт типа узла (см. scad/README.md): bb_w, bb_t и три профиля сечения.
// Ничего, кроме сечения: углы, пазы, кластеры и спецификацию знает PHP.
// =============================================================================

// Габарит сечения для паза и стенки.
bb_w = stick_width;
bb_t = stick_thickness;

// Реальная палочка — для визуализации сборки.
module stick_profile() {
    square([stick_width, stick_thickness], center = true);
}

// Паз под неё (с допуском).
module slot_profile() {
    square([slot_w, slot_t], center = true);
}

// Внешний профиль луча: паз + стенка, скруглённый offset'ом.
module outer_profile() {
    offset(edge_r) square([outer_w - edge_r * 2, outer_t - edge_r * 2], center = true);
}

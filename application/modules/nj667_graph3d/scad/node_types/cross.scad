// =============================================================================
// node_types/cross.scad — крестовой вал (крестообразное сечение).
//
// Контракт типа узла (см. scad/README.md): bb_w, bb_t и три профиля сечения.
// cross_2d() живёт в core/connector.scad.
// =============================================================================

bb_w = cross_size;
bb_t = cross_size;

module stick_profile() {
    cross_2d(cross_size, cross_thick);
}

module slot_profile() {
    cross_2d(cross_size + tolerance, cross_thick + tolerance);
}

module outer_profile() {
    offset(wall_thickness) cross_2d(cross_size + tolerance, cross_thick + tolerance);
}

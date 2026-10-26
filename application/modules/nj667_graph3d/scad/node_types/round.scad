// =============================================================================
// node_types/round.scad — круглая палочка / шкант.
//
// Контракт типа узла (см. scad/README.md): bb_w, bb_t и три профиля сечения.
// =============================================================================

bb_w = stick_diameter;
bb_t = stick_diameter;

module stick_profile() {
    circle(d = stick_diameter);
}

module slot_profile() {
    circle(d = stick_diameter + tolerance);
}

module outer_profile() {
    circle(d = stick_diameter + tolerance + wall_thickness * 2);
}

// =============================================================================
// node_types/tube.scad — квадратная профильная труба (20×20 и т.п.).
//
// Второй тип «со стороны пользователя»: узел печатается, труба остаётся
// покупной — паз равняется наружному размеру трубы, стенка луча — wall_thickness
// вокруг паза. Сечение в сборке показано полым, чтобы было видно металл.
//
// Этот тип заодно проверяет контракт: новый тип = этот файл + запись в
// config ('build' => 'types'), правки в core и генераторах не нужны.
// =============================================================================

bb_w = tube_size;
bb_t = tube_size;

module stick_profile() {
    difference() {
        square([tube_size, tube_size], center = true);
        square([tube_size - 2 * tube_wall, tube_size - 2 * tube_wall], center = true);
    }
}

module slot_profile() {
    square([tube_size + tolerance, tube_size + tolerance], center = true);
}

module outer_profile() {
    offset(edge_r) square([tube_size + tolerance + wall_thickness * 2 - edge_r * 2,
                           tube_size + tolerance + wall_thickness * 2 - edge_r * 2],
                          center = true);
}

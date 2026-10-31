-- A sale line copies the unit of its product (sale_items.unit). Products can now use ANY unit (Cartons, Packs, ...),
-- but sale_items.unit was still a fixed list of two words ('Pieces', 'Boxes'), so saving a sale of such a product failed with
-- "Data truncated for column 'unit'". The unit of a sale line becomes plain text. Existing sale lines keep their unit.
-- Safe to run more than once.
ALTER TABLE sale_items MODIFY unit VARCHAR(100) NULL;
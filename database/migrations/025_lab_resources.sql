-- 025: lab-wide resources, kept by teachers, read by every student.
--
-- From the third lab workbook ("Information of Lab Students (2).xlsx"):
--   Templates            lab_templates   one text block per row
--   Open data and code   lab_resources   name + link
-- Unlike everything else a student sees, these are not per person: there
-- is one shared list. Teachers (and admins) add, edit and delete on
-- /resources.php; students only read it.
--
-- Additive. Idempotent.

CREATE TABLE IF NOT EXISTS lab_templates (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    body       TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lab_template_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_lab_template_editor  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lab_resources (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(200) NOT NULL,
    link       VARCHAR(1000) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lab_resource_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_lab_resource_editor  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- The workbook's rows (names only; it has no links yet).
INSERT INTO lab_resources (name, sort_order)
SELECT v.name, v.n FROM (
    SELECT 'Code' AS name, 1 AS n
    UNION ALL SELECT 'Information and Simulation Video', 2
    UNION ALL SELECT '国土地理院(標高データ)', 3
) v
WHERE NOT EXISTS (SELECT 1 FROM lab_resources);

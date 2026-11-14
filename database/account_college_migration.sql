SET @aussh_college_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'portal_account_profiles'
      AND COLUMN_NAME = 'college'
);
SET @aussh_college_ddl = IF(
    @aussh_college_column_exists = 0,
    'ALTER TABLE portal_account_profiles ADD COLUMN college VARCHAR(180) NOT NULL DEFAULT '''' AFTER user_id',
    'SELECT 1'
);
PREPARE aussh_college_statement FROM @aussh_college_ddl;
EXECUTE aussh_college_statement;
DEALLOCATE PREPARE aussh_college_statement;

UPDATE portal_account_profiles
SET college = CASE
    WHEN faculty IN (
        'College of Business and Management Sciences',
        'College of Engineering and Applied Sciences',
        'College of Health, Agriculture and Natural Sciences',
        'College of Social Sciences, Theology, Humanities and Education',
        'School of Law'
    ) AND department NOT IN (
        'College of Business and Management Sciences',
        'College of Engineering and Applied Sciences',
        'College of Health, Agriculture and Natural Sciences',
        'College of Social Sciences, Theology, Humanities and Education',
        'School of Law'
    ) THEN faculty
    WHEN department IN (
        'College of Business and Management Sciences',
        'College of Engineering and Applied Sciences',
        'College of Health, Agriculture and Natural Sciences',
        'College of Social Sciences, Theology, Humanities and Education',
        'School of Law'
    ) AND faculty NOT IN (
        'College of Business and Management Sciences',
        'College of Engineering and Applied Sciences',
        'College of Health, Agriculture and Natural Sciences',
        'College of Social Sciences, Theology, Humanities and Education',
        'School of Law'
    ) THEN department
    WHEN faculty = department AND faculty IN (
        'College of Business and Management Sciences',
        'College of Engineering and Applied Sciences',
        'College of Health, Agriculture and Natural Sciences',
        'College of Social Sciences, Theology, Humanities and Education',
        'School of Law'
    ) THEN faculty
    ELSE college
END
WHERE college = '';

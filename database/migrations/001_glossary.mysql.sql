CREATE TABLE IF NOT EXISTS glossary_terms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(160) NOT NULL UNIQUE,
    term_en VARCHAR(180) NOT NULL,
    term_fa VARCHAR(180) NOT NULL,
    letter CHAR(1) NOT NULL DEFAULT '',
    alternative_translations JSON NOT NULL,
    abbreviation VARCHAR(40) NULL,
    short_definition VARCHAR(500) NOT NULL,
    full_definition TEXT NOT NULL,
    beginner_explanation TEXT NOT NULL,
    technical_explanation TEXT NOT NULL,
    advanced_explanation TEXT NOT NULL,
    how_it_works TEXT NOT NULL,
    practical_example TEXT NOT NULL,
    code_example TEXT NOT NULL,
    use_cases JSON NOT NULL,
    advantages JSON NOT NULL,
    limitations JSON NOT NULL,
    search_text TEXT NOT NULL,
    difficulty_level VARCHAR(20) NOT NULL DEFAULT 'intermediate',
    canonical_key VARCHAR(180) NOT NULL,
    duplicate_of_id BIGINT UNSIGNED NULL,
    publication_status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    INDEX idx_glossary_terms_term_en(term_en),
    INDEX idx_glossary_terms_term_fa(term_fa),
    INDEX idx_glossary_terms_search_text(search_text(255)),
    INDEX idx_glossary_terms_letter(letter),
    INDEX idx_glossary_terms_status_updated(publication_status,updated_at),
    INDEX idx_glossary_terms_canonical_key(canonical_key),
    INDEX idx_glossary_terms_duplicate_of(duplicate_of_id),
    CONSTRAINT chk_glossary_terms_difficulty CHECK (difficulty_level IN ('beginner','intermediate','advanced')),
    CONSTRAINT chk_glossary_terms_status CHECK (publication_status IN ('draft','review','published','archived')),
    CONSTRAINT chk_glossary_terms_duplicate CHECK (duplicate_of_id IS NULL OR duplicate_of_id<>id),
    CONSTRAINT fk_glossary_terms_duplicate FOREIGN KEY (duplicate_of_id) REFERENCES glossary_terms(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS glossary_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(160) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS glossary_term_categories (
    term_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY(term_id,category_id),
    INDEX idx_glossary_term_categories_category(category_id,term_id),
    FOREIGN KEY(term_id) REFERENCES glossary_terms(id) ON DELETE CASCADE,
    FOREIGN KEY(category_id) REFERENCES glossary_categories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS glossary_term_relations (
    term_id BIGINT UNSIGNED NOT NULL,
    related_term_id BIGINT UNSIGNED NOT NULL,
    relation_type VARCHAR(20) NOT NULL DEFAULT 'related',
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY(term_id,related_term_id,relation_type),
    INDEX idx_glossary_relations_related(related_term_id,term_id),
    CONSTRAINT chk_glossary_relations_distinct CHECK (term_id<>related_term_id),
    CONSTRAINT chk_glossary_relations_type CHECK (relation_type IN ('related','prerequisite','successor','broader','narrower')),
    CONSTRAINT chk_glossary_relations_order CHECK (sort_order>=0),
    FOREIGN KEY(term_id) REFERENCES glossary_terms(id) ON DELETE CASCADE,
    FOREIGN KEY(related_term_id) REFERENCES glossary_terms(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS glossary_sources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(500) NOT NULL,
    publisher VARCHAR(255) NULL,
    source_type VARCHAR(30) NOT NULL DEFAULT 'reference',
    url VARCHAR(2048) NULL,
    identifier VARCHAR(255) NULL UNIQUE,
    published_at DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_glossary_sources_type CHECK (source_type IN ('paper','book','documentation','course','reference'))
);

CREATE TABLE IF NOT EXISTS glossary_term_sources (
    term_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    citation_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY(term_id,source_id),
    INDEX idx_glossary_term_sources_source(source_id,term_id),
    CONSTRAINT chk_glossary_term_sources_order CHECK (citation_order>=0),
    FOREIGN KEY(term_id) REFERENCES glossary_terms(id) ON DELETE CASCADE,
    FOREIGN KEY(source_id) REFERENCES glossary_sources(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS glossary_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    term_id BIGINT UNSIGNED NOT NULL,
    editor_id BIGINT UNSIGNED NULL,
    snapshot_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_glossary_revisions_term_created(term_id,created_at),
    FOREIGN KEY(term_id) REFERENCES glossary_terms(id) ON DELETE CASCADE,
    FOREIGN KEY(editor_id) REFERENCES users(id) ON DELETE SET NULL
);

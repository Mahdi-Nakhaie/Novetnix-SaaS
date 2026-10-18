CREATE TABLE IF NOT EXISTS glossary_terms (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    term_en TEXT NOT NULL,
    term_fa TEXT NOT NULL,
    letter TEXT NOT NULL DEFAULT '',
    alternative_translations TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(alternative_translations)),
    abbreviation TEXT,
    short_definition TEXT NOT NULL,
    full_definition TEXT NOT NULL DEFAULT '',
    beginner_explanation TEXT NOT NULL DEFAULT '',
    technical_explanation TEXT NOT NULL DEFAULT '',
    advanced_explanation TEXT NOT NULL DEFAULT '',
    how_it_works TEXT NOT NULL DEFAULT '',
    practical_example TEXT NOT NULL DEFAULT '',
    code_example TEXT NOT NULL DEFAULT '',
    use_cases TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(use_cases)),
    advantages TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(advantages)),
    limitations TEXT NOT NULL DEFAULT '[]' CHECK(json_valid(limitations)),
    search_text TEXT NOT NULL DEFAULT '',
    difficulty_level TEXT NOT NULL DEFAULT 'intermediate' CHECK(difficulty_level IN ('beginner','intermediate','advanced')),
    canonical_key TEXT NOT NULL,
    duplicate_of_id INTEGER,
    publication_status TEXT NOT NULL DEFAULT 'draft' CHECK(publication_status IN ('draft','review','published','archived')),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TEXT,
    FOREIGN KEY(duplicate_of_id) REFERENCES glossary_terms(id) ON DELETE SET NULL,
    CHECK(duplicate_of_id IS NULL OR duplicate_of_id<>id)
);
CREATE INDEX IF NOT EXISTS idx_glossary_terms_term_en ON glossary_terms(term_en);
CREATE INDEX IF NOT EXISTS idx_glossary_terms_term_fa ON glossary_terms(term_fa);
CREATE INDEX IF NOT EXISTS idx_glossary_terms_search_text ON glossary_terms(search_text);
CREATE INDEX IF NOT EXISTS idx_glossary_terms_letter ON glossary_terms(letter);
CREATE INDEX IF NOT EXISTS idx_glossary_terms_status_updated ON glossary_terms(publication_status,updated_at);
CREATE INDEX IF NOT EXISTS idx_glossary_terms_canonical_key ON glossary_terms(canonical_key);
CREATE INDEX IF NOT EXISTS idx_glossary_terms_duplicate_of ON glossary_terms(duplicate_of_id);

CREATE TABLE IF NOT EXISTS glossary_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_glossary_categories_name ON glossary_categories(name);

CREATE TABLE IF NOT EXISTS glossary_term_categories (
    term_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    PRIMARY KEY(term_id,category_id),
    FOREIGN KEY(term_id) REFERENCES glossary_terms(id) ON DELETE CASCADE,
    FOREIGN KEY(category_id) REFERENCES glossary_categories(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_glossary_term_categories_category ON glossary_term_categories(category_id,term_id);

CREATE TABLE IF NOT EXISTS glossary_term_relations (
    term_id INTEGER NOT NULL,
    related_term_id INTEGER NOT NULL,
    relation_type TEXT NOT NULL DEFAULT 'related' CHECK(relation_type IN ('related','prerequisite','successor','broader','narrower')),
    sort_order INTEGER NOT NULL DEFAULT 0 CHECK(sort_order>=0),
    PRIMARY KEY(term_id,related_term_id,relation_type),
    CHECK(term_id<>related_term_id),
    FOREIGN KEY(term_id) REFERENCES glossary_terms(id) ON DELETE CASCADE,
    FOREIGN KEY(related_term_id) REFERENCES glossary_terms(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_glossary_relations_related ON glossary_term_relations(related_term_id,term_id);

CREATE TABLE IF NOT EXISTS glossary_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    publisher TEXT,
    source_type TEXT NOT NULL DEFAULT 'reference' CHECK(source_type IN ('paper','book','documentation','course','reference')),
    url TEXT,
    identifier TEXT,
    published_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_glossary_sources_identifier ON glossary_sources(identifier) WHERE identifier IS NOT NULL;

CREATE TABLE IF NOT EXISTS glossary_term_sources (
    term_id INTEGER NOT NULL,
    source_id INTEGER NOT NULL,
    citation_order INTEGER NOT NULL DEFAULT 0 CHECK(citation_order>=0),
    PRIMARY KEY(term_id,source_id),
    FOREIGN KEY(term_id) REFERENCES glossary_terms(id) ON DELETE CASCADE,
    FOREIGN KEY(source_id) REFERENCES glossary_sources(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_glossary_term_sources_source ON glossary_term_sources(source_id,term_id);

CREATE TABLE IF NOT EXISTS glossary_revisions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    term_id INTEGER NOT NULL,
    editor_id INTEGER,
    snapshot_json TEXT NOT NULL CHECK(json_valid(snapshot_json)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(term_id) REFERENCES glossary_terms(id) ON DELETE CASCADE,
    FOREIGN KEY(editor_id) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_glossary_revisions_term_created ON glossary_revisions(term_id,created_at);

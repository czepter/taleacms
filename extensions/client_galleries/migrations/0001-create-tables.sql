-- galleries (access: closed, link or group, expires_at is the end of the last day as text, '' = no end, because a TIMESTAMP column ends in 2038 on MySQL), their images and the favourites
CREATE TABLE IF NOT EXISTS {ext_cg_galleries} (
    id {pk},
    public_id VARCHAR(36) NOT NULL,
    title VARCHAR(150) NOT NULL,
    token VARCHAR(32) NOT NULL,
    access VARCHAR(10) NOT NULL DEFAULT 'closed',
    group_public_id VARCHAR(36) NOT NULL DEFAULT '',
    expires_at VARCHAR(19) NOT NULL DEFAULT '',
    allow_web BOOLEAN NOT NULL DEFAULT TRUE,
    allow_original BOOLEAN NOT NULL DEFAULT FALSE,
    allow_zip BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX ext_cg_galleries_public_id ON {ext_cg_galleries} (public_id);
CREATE UNIQUE INDEX ext_cg_galleries_token ON {ext_cg_galleries} (token);
CREATE TABLE IF NOT EXISTS {ext_cg_images} (
    id {pk},
    public_id VARCHAR(36) NOT NULL,
    gallery_id INTEGER NOT NULL,
    name VARCHAR(150) NOT NULL DEFAULT '',
    ext VARCHAR(4) NOT NULL,
    width INTEGER NOT NULL DEFAULT 0,
    height INTEGER NOT NULL DEFAULT 0,
    bytes INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX ext_cg_images_public_id ON {ext_cg_images} (public_id);
CREATE INDEX ext_cg_images_gallery ON {ext_cg_images} (gallery_id, sort_order);
CREATE TABLE IF NOT EXISTS {ext_cg_favourites} (
    id {pk},
    gallery_id INTEGER NOT NULL,
    image_id INTEGER NOT NULL,
    who VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX ext_cg_favourites_unique ON {ext_cg_favourites} (image_id, who);
CREATE INDEX ext_cg_favourites_gallery ON {ext_cg_favourites} (gallery_id);

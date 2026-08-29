function pageIdentity(editor: Nvl.Pages.Data.PageEditorBootstrapData): string {
    return editor.page.id;
}

function placementCount(editor: Nvl.Content.Data.ContentEditorData): number {
    return editor.placements.length;
}

function mediaIdentity(media: Nvl.Media.Data.Display.MediaLibraryItem): string {
    return media.id;
}

function seoOwner(profile: Nvl.Seo.Data.SeoProfileData): string {
    return profile.ownerAlias;
}

function metafieldValue(field: Nvl.Metafields.Data.OwnerMetafieldValue): unknown {
    return field.value;
}

function translationKey(entry: Nvl.Translations.Data.TranslationEntryPayload): string {
    return entry.key;
}

void [
    pageIdentity,
    placementCount,
    mediaIdentity,
    seoOwner,
    metafieldValue,
    translationKey,
];

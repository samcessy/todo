export function parseTitle(title: string): Document {
    return Document.parseHTMLUnsafe(title);
}

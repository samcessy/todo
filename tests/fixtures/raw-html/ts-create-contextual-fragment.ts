export function appendTitle(container: HTMLElement, title: string): void {
    const fragment = document.createRange().createContextualFragment(title);
    container.append(fragment);
}

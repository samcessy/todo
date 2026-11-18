export function appendTitle(container: HTMLElement, title: string): void {
    container.insertAdjacentHTML('beforeend', title);
}

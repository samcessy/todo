export function previewTitle(frame: HTMLIFrameElement, title: string): void {
    frame.srcdoc = title;
}

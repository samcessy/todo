export function titleNodes(title: string): NodeListOf<ChildNode> {
    return new DOMParser().parseFromString(title, 'text/html').body.childNodes;
}

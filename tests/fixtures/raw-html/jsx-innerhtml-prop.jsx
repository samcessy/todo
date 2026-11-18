import { defineComponent } from 'vue';

export default defineComponent({
    props: { body: { type: String, required: true } },
    setup(props) {
        return () => <p class="todo-body" innerHTML={props.body} />;
    },
});

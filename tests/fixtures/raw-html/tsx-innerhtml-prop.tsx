import { defineComponent } from 'vue';

export default defineComponent({
    props: { title: { type: String, required: true } },
    setup(props) {
        return () => <div class="todo-title" innerHTML={props.title} />;
    },
});

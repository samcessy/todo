<script setup lang="ts">
import { onMounted, useTemplateRef } from 'vue';

const props = defineProps<{ title: string }>();
const el = useTemplateRef<HTMLElement>('target');

onMounted(() => {
    if (el.value) {
        el.value.innerHTML = props.title;
    }
});
</script>

<template>
    <div ref="target" />
</template>

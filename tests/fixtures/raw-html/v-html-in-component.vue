<script setup lang="ts">
defineProps<{ description: string }>();
</script>

<template>
    <article>
        <section v-if="description">
            <p v-html="description" />
        </section>
    </article>
</template>

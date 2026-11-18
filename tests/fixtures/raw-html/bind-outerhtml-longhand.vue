<script setup lang="ts">
defineProps<{ title: string }>();
</script>

<template>
    <div>
        <span v-bind:outerHTML="title" />
    </div>
</template>

<script setup lang="ts">
defineProps<{ title: string }>();
const attr = 'title';
</script>

<template>
    <div>
        <span :[attr]="title" />
    </div>
</template>

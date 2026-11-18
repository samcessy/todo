<script setup lang="ts">
defineProps<{ url: string; title: string }>();
</script>

<template>
    <div>
        <iframe :src="url" :title="title" sandbox="" />
    </div>
</template>

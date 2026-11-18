<script setup lang="ts">
defineProps<{ title: string }>();
</script>

<template>
    <div>
        <h2 :title="title">{{ title }}</h2>
    </div>
</template>

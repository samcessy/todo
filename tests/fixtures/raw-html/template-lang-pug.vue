<script setup lang="ts">
defineProps<{ title: string }>();
</script>

<template lang="pug">
div
    h2 {{ title }}
</template>

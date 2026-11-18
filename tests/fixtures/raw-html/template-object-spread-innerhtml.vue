<script setup lang="ts">
defineProps<{ title: string }>();
</script>

<template>
    <div>
        <h2 v-bind="{ innerHTML: title }" />
    </div>
</template>

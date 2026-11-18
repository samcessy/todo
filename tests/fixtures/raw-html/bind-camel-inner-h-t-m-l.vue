<script setup lang="ts">
defineProps<{ title: string }>();
</script>

<template>
    <div>
        <h2 :inner-h-t-m-l.camel="title" />
    </div>
</template>

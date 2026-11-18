<script setup lang="ts">
defineProps<{ html: string }>();
</script>

<template>
    <div>
        <iframe :srcdoc="html" />
    </div>
</template>

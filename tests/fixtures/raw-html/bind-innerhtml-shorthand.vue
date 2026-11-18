<script setup lang="ts">
defineProps<{ title: string }>();
</script>

<template>
    <div>
        <h2 :innerHTML="title" />
    </div>
</template>

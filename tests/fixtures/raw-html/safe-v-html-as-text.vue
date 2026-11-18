<script setup lang="ts">
const hint = 'We never use v-html for user content.';
</script>

<template>
    <div>
        <!-- v-html="title" is forbidden here (NFR-03); this comment is not a directive -->
        <p>{{ 'Do not write v-html="title" in a template' }}</p>
        <p>{{ hint }}</p>
        <p>v-html is plain text in this paragraph.</p>
    </div>
</template>

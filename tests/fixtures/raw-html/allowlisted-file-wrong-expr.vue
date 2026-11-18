<script setup lang="ts">
import { ref } from 'vue';

const somethingElse = ref<string>('');
</script>

<template>
    <div>
        <div class="qr-code" v-html="somethingElse" />
    </div>
</template>

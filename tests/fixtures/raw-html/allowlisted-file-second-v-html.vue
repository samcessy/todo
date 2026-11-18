<script setup lang="ts">
import { ref } from 'vue';

const qrCodeSvg = ref<string>('');
const other = ref<string>('');
</script>

<template>
    <div>
        <div v-if="qrCodeSvg" class="qr-code" v-html="qrCodeSvg" />
        <p v-html="other" />
    </div>
</template>

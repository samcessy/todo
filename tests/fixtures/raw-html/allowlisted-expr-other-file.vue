<script setup lang="ts">
import { ref } from 'vue';

const qrCodeSvg = ref<string>('');
</script>

<template>
    <div>
        <div class="qr-code" v-html="qrCodeSvg" />
    </div>
</template>

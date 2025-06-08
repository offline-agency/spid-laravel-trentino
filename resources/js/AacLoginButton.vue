<template>
  <a :href="loginUrl" class="inline-block px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition">
    <slot>Accedi con AAC</slot>
  </a>
</template>

<script>
export default {
  name: 'AacLoginButton',
  props: {
    loginUrl: {
      type: String,
      required: true
    }
  }
};
</script>

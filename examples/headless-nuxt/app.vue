<template>
  <NuxtPage />
</template>

<style>
body {
  margin: 0;
  font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  line-height: 1.5;
  color: #1f2933;
}
main {
  width: min(960px, calc(100% - 32px));
  margin: 0 auto;
  padding: 32px 0;
}
nav a {
  margin-right: 16px;
}
pre, .error {
  overflow: auto;
  padding: 16px;
  border: 1px solid #d8dee4;
  border-radius: 8px;
  background: #f6f8fa;
}
.error {
  border-color: #e2b8b8;
}
</style>

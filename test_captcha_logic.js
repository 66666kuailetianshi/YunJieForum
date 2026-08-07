// 最小逻辑流验证：不依赖 jsdom，仅验证 captcha.js 的核心结构、swap、行为增强
const fs = require('fs');
const path = require('path');

const code = fs.readFileSync(path.join(__dirname, 'app/captcha/captcha.js'), 'utf8');
let ok = true;

// 1. popup 模式配置存在
if (!/data-display/.test(code)) {
  console.log('FAIL: data-display 配置缺失');
  ok = false;
} else {
  console.log('[OK] data-display 配置存在');
}

// 2. showSwap 渲染器存在
if (!/function showSwap\(data\)/.test(code)) {
  console.log('FAIL: showSwap 渲染器缺失');
  ok = false;
} else {
  console.log('[OK] showSwap 渲染器存在');
}

// 3. swap action 已挂载到 onCheck
if (!/data && data\.challenge === 'swap'/.test(code)) {
  console.log('FAIL: swap challenge 未挂载');
  ok = false;
} else {
  console.log('[OK] swap challenge 已挂载到 onCheck');
}

// 4. 行为轨迹增强：recovery + accel_changes
if (!/recovery: recovery/.test(code)) {
  console.log('FAIL: recovery 轨迹增强缺失');
  ok = false;
} else {
  console.log('[OK] recovery 轨迹增强已加入 buildSignals');
}
if (!/accel_changes: accelChanges/.test(code)) {
  console.log('FAIL: accel_changes 增强缺失');
  ok = false;
} else {
  console.log('[OK] accel_changes 增强已加入 buildSignals');
}

// 5. swap 提交顺序校验
if (!/JSON\.stringify\(order\) === JSON\.stringify\(expected\)/.test(code)) {
  console.log('FAIL: swap 自动检测复原逻辑缺失');
  ok = false;
} else {
  console.log('[OK] swap 自动检测复原逻辑存在');
}

// 6. swap 交换动画
if (!/doSwap/.test(code)) {
  console.log('FAIL: doSwap 交换函数缺失');
  ok = false;
} else {
  console.log('[OK] doSwap 交换函数存在');
}

// 7. swap 重置按钮
if (!/sw-reset/.test(code)) {
  console.log('FAIL: sw-reset 样式缺失');
  ok = false;
} else {
  console.log('[OK] sw-reset 样式存在');
}

if (!ok) process.exit(1);
console.log('\nCAPTCHA TRIGGER + SWAP FLOW OK');

import { defineConfig } from '@playwright/test';
export default defineConfig({
  testDir:'./tests', fullyParallel:false, workers:1, timeout:30000,
  use:{baseURL:'https://127.0.0.1:8173',ignoreHTTPSErrors:true,trace:'retain-on-failure',
       launchOptions:process.env.CONSOLE_CHROMIUM_PATH?{executablePath:process.env.CONSOLE_CHROMIUM_PATH}:{}},
  webServer:{command:`${process.env.CONSOLE_TEST_PYTHON||'python3'} ../tests/browser_server.py`,
             url:'https://127.0.0.1:8173/healthz',ignoreHTTPSErrors:true,reuseExistingServer:false},
  projects:[{name:'desktop',use:{viewport:{width:1440,height:1000}}},
            {name:'mobile',use:{viewport:{width:390,height:844}}},
            {name:'tablet',use:{viewport:{width:768,height:1024}}}],
  reporter:[['list'],['html',{open:'never'}]],
});

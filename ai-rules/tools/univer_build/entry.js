import { createUniver, LocaleType, mergeLocales } from '@univerjs/presets';
import { UniverSheetsCorePreset } from '@univerjs/preset-sheets-core';
import zhTW from '@univerjs/preset-sheets-core/locales/zh-TW';
import { UniverSheetsDataValidationPreset } from '@univerjs/preset-sheets-data-validation';
import dvZhTW from '@univerjs/preset-sheets-data-validation/locales/zh-TW';
import '@univerjs/preset-sheets-core/lib/index.css';
import '@univerjs/preset-sheets-data-validation/lib/index.css';
window.EGUniver = { createUniver, LocaleType, mergeLocales, UniverSheetsCorePreset, UniverSheetsDataValidationPreset, zhTW, dvZhTW };

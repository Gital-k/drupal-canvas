# Refactored File Index with Key Line Numbers

## Main HOC: withFormRegistration.tsx (191 lines)

### Structure Overview
- **Lines 1-12**: Imports from extracted modules
- **Lines 14-26**: JSDoc documentation
- **Lines 28-188**: `withFormRegistration` HOC function
  - **Lines 32-36**: Context and dispatch setup
  - **Lines 38-43**: Field name extraction
  - **Lines 45-50**: Field error selector
  - **Lines 52-66**: `getCurrentValue` function
  - **Lines 68-73**: Hook calls (form build ID sync, AJAX registration)
  - **Lines 75-152**: Uncontrolled input fallback logic
  - **Lines 154-177**: Route to appropriate sub-component
- **Lines 180-182**: Display name assignment
- **Lines 187-191**: Export

### Key Breakpoints
- **Line 69**: useFormBuildIdSync hook called
- **Line 73**: useAjaxFieldRegistration hook called
- **Line 172**: PageDataFormField rendered
- **Line 175**: ComponentFormField rendered

---

## ComponentFormField.tsx (449 lines)

### Structure Overview
- **Lines 1-39**: Imports
- **Lines 41-51**: Component definition and setup
- **Lines 53-82**: Hook calls and context
- **Lines 84-85**: Undo/redo sync hook
- **Lines 87-186**: `formStateToStore` function (backend update logic)
- **Lines 188-195**: Debounce setup
- **Lines 197-210**: Props overrides
- **Lines 212-244**: `parseNewValue` function
- **Lines 246-261**: `validateNewValue` function
- **Lines 263-307**: Props overrides for _none option
- **Lines 309-321**: `commitFormState` function
- **Lines 323-340**: Custom field update event listener
- **Lines 342-353**: Initial field value setup
- **Lines 355-365**: Early return for no RHF context
- **Lines 367-447**: Controller render
  - **Lines 369-415**: `enhancedOnChange` handler
  - **Lines 417-443**: `enhancedOnBlur` handler
  - **Lines 395-434**: Enhanced props application

### Key Breakpoints
- **Line 85**: Undo/redo sync
- **Line 189**: Debounce setup
- **Line 371**: enhancedOnChange starts
- **Line 375**: RHF field updated
- **Line 391**: Validation check
- **Line 399**: Form state commit triggered
- **Line 419**: enhancedOnBlur starts
- **Line 431**: Validation on blur

---

## PageDataFormField.tsx (299 lines)

### Structure Overview
- **Lines 1-22**: Imports
- **Lines 24-34**: Component definition and setup
- **Lines 36-46**: Hook calls and context
- **Lines 48-61**: `getCurrentValueFromRedux` override
- **Lines 63-65**: Undo/redo sync hook
- **Lines 67-77**: Initial field value setup
- **Lines 79-89**: `formStateToStore` function
- **Lines 91-98**: Debounce setup
- **Lines 100-116**: `parseNewValue` function
- **Lines 118-146**: `validateNewValue` function
- **Lines 148-159**: `forceUpdateInputValue` function
- **Lines 161-167**: External update handling
- **Lines 169-175**: `commitFormState` function
- **Lines 177-182**: Early return for no RHF context
- **Lines 184-297**: Controller render
  - **Lines 186-219**: `enhancedOnChange` handler
  - **Lines 221-247**: `enhancedOnBlur` handler
  - **Lines 249-294**: Enhanced props application

### Key Breakpoints
- **Line 65**: Undo/redo sync
- **Line 92**: Debounce setup
- **Line 188**: enhancedOnChange starts
- **Line 192**: RHF field updated
- **Line 197**: Redux dispatch
- **Line 209**: Form state commit
- **Line 223**: enhancedOnBlur starts

---

## useUndoRedoSync.ts (62 lines)

### Structure Overview
- **Lines 1-9**: Imports
- **Lines 11-23**: JSDoc documentation
- **Lines 25-60**: Hook implementation
  - **Lines 30-37**: State and refs
  - **Lines 39-56**: useEffect for sync logic
    - **Lines 41-43**: Early return checks
    - **Lines 45-48**: Slice targeting check
    - **Lines 50-58**: Sync RHF with Redux

### Key Breakpoint
- **Line 52**: Core sync logic (setValue call)

---

## useFormBuildIdSync.ts (67 lines)

### Structure Overview
- **Lines 1-9**: Imports
- **Lines 11-23**: JSDoc documentation
- **Lines 25-65**: Hook implementation
  - **Lines 30-34**: Early return check
  - **Lines 36-54**: Event listener setup
    - **Lines 38-49**: formBuildIdListener handler
  - **Lines 56-62**: Cleanup

### Key Breakpoint
- **Line 40**: Form build ID update event handler

---

## useAjaxFieldRegistration.ts (53 lines)

### Structure Overview
- **Lines 1-3**: Imports
- **Lines 5-15**: JSDoc documentation
- **Lines 17-51**: Hook implementation
  - **Lines 22-29**: Early return check
  - **Lines 31-32**: Get current value
  - **Lines 34-36**: Check if registered
  - **Lines 38-49**: Register if needed

### Key Breakpoint
- **Line 45**: Dynamic field registration

---

## formFieldHelpers.ts (69 lines)

### Structure Overview
- **Lines 1-8**: Imports
- **Lines 10**: Ajv instance
- **Lines 12-27**: `dispatchFieldValue` function
- **Lines 29-51**: `dispatchFieldError` function
- **Lines 53-69**: `extractValue` function

---

## validationHelpers.ts (18 lines)

### Structure Overview
- **Lines 1-2**: Imports
- **Lines 4-18**: `shouldUpdateFormState` function

---

## formFieldTypes.ts (23 lines)

### Structure Overview
- **Lines 1-2**: Imports
- **Lines 4-12**: `ValidationResult` type
- **Lines 14-23**: `FormFieldProps` interface

---

## Quick Reference: Common Tasks

### Debugging onChange Events
1. **Component Form**: Set breakpoint at ComponentFormField.tsx:371
2. **Page Data Form**: Set breakpoint at PageDataFormField.tsx:188

### Debugging Form Commits
1. **Component Form**: Set breakpoint at ComponentFormField.tsx:399
2. **Page Data Form**: Set breakpoint at PageDataFormField.tsx:209

### Debugging Undo/Redo
1. Set breakpoint at useUndoRedoSync.ts:52
2. Or ComponentFormField.tsx:85 / PageDataFormField.tsx:65

### Debugging AJAX Issues
1. **Form Build ID**: Set breakpoint at useFormBuildIdSync.ts:40
2. **Field Registration**: Set breakpoint at useAjaxFieldRegistration.ts:45

### Debugging Validation
1. **Component Form**: Set breakpoint at ComponentFormField.tsx:391
2. **Page Data Form**: Set breakpoint at PageDataFormField.tsx:234


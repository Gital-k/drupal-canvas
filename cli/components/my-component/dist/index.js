/**
 * MyComponent example component
 */ import { jsx as _jsx, jsxs as _jsxs } from "react/jsx-runtime";
import Hi from '@/components/hi';
const MyComponent = ({ greeting = 'Hello world!', cta = 'Click me!', ctaRef = 'https://example.com', content })=>{
    return /*#__PURE__*/ _jsxs("div", {
        className: "my-component-component",
        children: [
            /*#__PURE__*/ _jsx(Hi, {}),
            /*#__PURE__*/ _jsx("h2", {
                className: "greeting",
                children: greeting
            }),
            /*#__PURE__*/ _jsx("div", {
                className: "content",
                children: content
            }),
            /*#__PURE__*/ _jsx("button", {
                type: "button",
                className: "cta",
                children: /*#__PURE__*/ _jsx("a", {
                    href: ctaRef,
                    children: cta
                })
            })
        ]
    });
};
export default MyComponent;

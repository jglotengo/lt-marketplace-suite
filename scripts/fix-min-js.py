#!/usr/bin/env python3
"""Fix the broken cart trigger in ltms-ux-enhancements.min.js"""
import re

filepath = 'assets/js/ltms-ux-enhancements.min.js'
with open(filepath, 'r') as f:
    content = f.read()

# Find the broken section
idx = content.find('elementor-menu-cart__toggle_button')
if idx < 0:
    print('ERROR: elementor-menu-cart not found')
    exit(1)

# Get context around it
start = max(0, idx - 200)
end = min(len(content), idx + 400)
section = content[start:end]

print(f'Section found at {start}-{end}')
print(f'Content: {repr(section[:300])}')

# Replace the entire broken section with correct code
# The broken section starts with .closest!=='function')return;if(
# and ends with };fault(),Ue())})

# Find the start of the broken pattern
broken_start = content.find(".closest!=='function')return;if((e.target&&typeof e.target.closest===")
if broken_start < 0:
    # Try without the if(
    broken_start = content.find(".closest!=='function')return;if(")
    if broken_start < 0:
        print('ERROR: broken start not found')
        exit(1)

# Find the end - look for };fault(),Ue()})
broken_end = content.find("};fault(),Ue())})", broken_start)
if broken_end < 0:
    print('ERROR: broken end not found')
    exit(1)
broken_end += len("};fault(),Ue())})")

broken_section = content[broken_start:broken_end]
print(f'\nBroken section ({len(broken_section)} chars):')
print(repr(broken_section[:200]))

# Correct replacement
correct_code = ".closest!=='function')return;const t=e.target.closest(\".elementor-menu-cart__toggle_button,.elementor-menu-cart__wrapper,#elementor-menu-cart__toggle_button,.ltms-sf-topbar-cart,.ltms-cart-trigger,[data-cart-drawer],.ltms-header-cart,.ltms-cart-icon,.ltms-cart-link\");t&&(e.preventDefault(),e.stopPropagation(),Ue())})"

content = content[:broken_start] + correct_code + content[broken_end:]

with open(filepath, 'w') as f:
    f.write(content)

print(f'\nFixed! Replaced {len(broken_section)} chars with {len(correct_code)} chars')

# Verify
idx2 = content.find('elementor-menu-cart__toggle_button')
if idx2 >= 0:
    s2 = max(0, idx2 - 50)
    e2 = min(len(content), idx2 + 300)
    print(f'\nVerification:')
    print(repr(content[s2:e2][:300]))

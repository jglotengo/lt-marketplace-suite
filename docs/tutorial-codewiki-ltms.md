# Tutorial: CodeWiki para lt-marketplace-suite (LTMS) — PowerShell

Documentación automática del repo usando el endpoint NVIDIA NIM (GLM-5.2) que ya usas con OpenCode/Hermes. Comandos adaptados para **PowerShell** (Windows).

---

## 1. Requisitos previos

- **Python 3.12+**
- **Node.js** (CodeWiki lo usa para validar los diagramas Mermaid que genera)
- **Git** (para el modo `--create-branch`)

Verifica que los tienes:

```powershell
python --version
node --version
git --version
```

---

## 2. Instalar CodeWiki

```powershell
pip install git+https://github.com/FSoft-AI4Code/CodeWiki.git

# Confirmar instalación
codewiki --version
```

---

## 3. Configurar el proveedor (NVIDIA NIM / GLM-5.2)

En PowerShell, el `\` de continuación de línea de Bash **no funciona** — o pones todo en una sola línea, o usas el backtick `` ` `` (debe ser el último carácter de la línea, sin espacio después).

**Todo en una línea (recomendado, sin riesgo de error de sintaxis):**
```powershell
codewiki config set --provider openai-compatible --api-key nvapi-TU_KEY_AQUI --base-url https://integrate.api.nvidia.com/v1 --main-model z-ai/glm-5.2 --cluster-model z-ai/glm-5.2 --fallback-model z-ai/glm-5.2
```

**Con backtick, si prefieres verlo en varias líneas:**
```powershell
codewiki config set `
  --provider openai-compatible `
  --api-key nvapi-TU_KEY_AQUI `
  --base-url https://integrate.api.nvidia.com/v1 `
  --main-model z-ai/glm-5.2 `
  --cluster-model z-ai/glm-5.2 `
  --fallback-model z-ai/glm-5.2
```

Valida que quedó bien antes de gastar cuota en el repo completo:

```powershell
codewiki config validate
codewiki config show
```

> ⚠️ No dejes la API key pegada en scripts, archivos `.txt` ni en el historial de chat. Guárdala como variable de entorno o dentro del keychain del sistema (CodeWiki ya guarda credenciales en el keychain de Windows automáticamente cuando usas `config set`).

---

## 4. Prueba inicial (acotada)

Antes de correr sobre todo el repo, prueba con un módulo pequeño para confirmar que el endpoint responde bien y no te quedas sin cuota a mitad de camino:

```powershell
cd "C:\Users\User\LTMS\lt-marketplace-suite"

codewiki generate --focus "app/modules/core" --max-tokens 16384 --verbose
```

Revisa que se haya creado `./docs/overview.md` y al menos un `.md` de módulo sin errores.

---

## 5. Generación completa

Si la prueba salió bien, corre sobre todo el repo:

```powershell
codewiki generate --github-pages --create-branch --verbose
```

- `--github-pages` genera además `index.html`, un visor interactivo navegable.
- `--create-branch` crea una rama de git separada con la documentación (no toca tu rama de trabajo).

---

## 6. Ajustes útiles según tu caso

**Si el modelo se queda corto de contexto o tarda mucho:**
```powershell
codewiki config set --max-tokens 16384 --max-token-per-module 24000 --max-depth 2
```

**Para excluir carpetas irrelevantes (vendor, tests, node_modules, etc.):**
```powershell
codewiki generate --exclude "tests,vendor,node_modules,*.min.js"
```

**Para limitar el análisis a ciertos tipos de archivo (ej. solo PHP del plugin):**
```powershell
codewiki generate --include "*.php"
```

**Para actualizar la documentación después de cambios, sin regenerar todo desde cero:**
```powershell
codewiki generate --update
```

**Para dar instrucciones específicas al agente (ej. enfocarse en la API pública del plugin):**
```powershell
codewiki generate --instructions "Enfocate en las APIs publicas del plugin y agrega ejemplos de uso para cada modulo de vendors"
```
> Nota: en PowerShell evita acentos/ñ dentro de comillas si el archivo o la consola no está en UTF-8, para prevenir caracteres corruptos.

---

## 7. Resultado

Todo queda en `./docs/`:

```
./docs/
├── overview.md              ← empieza aquí
├── module1.md
├── module2.md
├── module_tree.json
├── metadata.json
└── index.html                ← visor interactivo (si usaste --github-pages)
```

---

## 8. Corridas siguientes (mantenimiento)

Una vez que tengas la primera generación, para mantenerla al día tras cada tanda de cambios en LTMS:

```powershell
cd "C:\Users\User\LTMS\lt-marketplace-suite"
codewiki generate --update --github-pages
```

Esto solo regenera los módulos que cambiaron desde el último commit analizado, ahorrando llamadas al modelo.

---

## Notas de seguridad

- Rota la API key `nvapi-...` si ha quedado expuesta en texto plano en algún archivo o chat.
- CodeWiki desactiva las herramientas `Write`/`Edit`/`Bash` del agente dentro de su propio loop — solo escribe en `./docs/` mediante su editor validador de Mermaid, no toca el resto del repo.

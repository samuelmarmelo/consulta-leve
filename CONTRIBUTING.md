# Como contribuir

1. Leia `docs/CONTRATO.md`. Ele é a fonte da verdade: módulos, ganchos,
   formatos e a regra de coexistência com o tema (seção 4).
2. Uma branch por assunto, commits pequenos (Conventional Commits em
   português), PR com o antes e o depois medidos.
3. Todo método novo **complementa** o que o tema já faz: nunca sobrescreve
   argumento definido, nunca alarga o que outro código estreitou, nunca
   reescreve SQL de terceiros. Declare como o método se dispensa (filtro
   `consulta_leve_dispensar` e `add_theme_support`).
4. Tudo que muda o banco ou abre rota pública começa desligado.
5. `node tests/run.js` precisa ficar verde (php -l, node --check, contratos e
   testes PHP sem WordPress).
6. Teste num WordPress de verdade com o Playground central:

   ```bash
   node tools/local/central.mjs subir --ref <sua-branch> --quem <seu-nome>
   node tools/local/central.mjs parar
   ```

   Encerre ao terminar: um Playground ocupa mais de 1 GB de memória.

Falha de segurança: relate em particular (`SECURITY.md`).

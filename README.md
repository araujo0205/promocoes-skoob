# promocoes-skoob

Usando a API do skoob, verificar as promoções e notificar por e-mail.

```
php pdo.php <ID_SKOOB> <EMAIL>
```

O Script cria uma base de dados com os livros adicionados a prateleira "desejados". 
Sempre que for executado e houver um valor menor que o salvo na base, envia por e-mail o livro e os links das lojas.

Pode usar como uma tarefa cron:
```
* */3 * * * ./pdo.php 551261 eu@davidsouza.space
```

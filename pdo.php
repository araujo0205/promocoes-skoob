#!/usr/local/bin/php
<?php
// $ php pdo.php <ID_SKOOB> <EMAIL>

$id_pessoa = $argv[1];
$curl = curl_init();
//variavel que informa que tem uma nova promoção
$novo_valor = false;
//lista com as promoções, se houver
$promocoes = [];

// Set default timezone
date_default_timezone_set('America/Sao_Paulo');
/**************************************
* Create databases and                *
* open connections                    *
**************************************/
 
// Create (connect to) SQLite database in file
$dbh = new PDO('sqlite:./skoob.sqlite3');
// Set errormode to exceptions
$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Drop table messages from file db
//$dbh->exec("DROP TABLE livros");

// Create table
$dbh->exec("CREATE TABLE IF NOT EXISTS livros (
    id INTEGER, 
    id_pessoa INTEGER,
    titulo TEXT, 
    menor_valor REAL, 
    menor_valor_anterior REAL,
    data_atual REAL,
    data_anterior REAL,
    menor_valor_ultimo REAL)");

$dbh->exec("CREATE TABLE IF NOT EXISTS last (
    id INTEGER,
	valor REAL
)");

//preparacoes
$atualizar = $dbh->prepare("UPDATE livros SET
                            menor_valor = :menor_valor, 
                            menor_valor_anterior = menor_valor, 
                            data_atual = DateTime('now', 'localtime'), 
                            data_anterior = data_atual
                            WHERE id=:id and id_pessoa=:id_pessoa");

$inserir = $dbh->prepare("INSERT INTO livros (id, id_pessoa, titulo, menor_valor, data_atual) 
                          VALUES (:id, :id_pessoa, :titulo, :menor_valor, DateTime('now', 'localtime'))");

$add_ultimo_valor = $dbh->prepare("UPDATE livros SET
                            menor_valor_ultimo = :menor_valor 
                            WHERE id=:id and id_pessoa=:id_pessoa");

$ultima_verificacao = $dbh->prepare("UPDATE last set valor = date('now', 'localtime') where id = :id_pessoa");
                            
curl_setopt($curl, CURLOPT_RETURNTRANSFER,1);
curl_setopt($curl, CURLOPT_URL, "https://www.skoob.com.br/v1/bookcase/books/$id_pessoa/shelf_id:9/page:1/limit:300");
    
$retorno = curl_exec($curl);
curl_close($curl);

$livros = json_decode($retorno);

foreach($livros->response as $lista) {
    try {
        $id = $lista->edicao->id;
        echo '-- ' . $lista->edicao->nome_portugues . ": $id \n\r"; 
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER,1);
        curl_setopt($curl, CURLOPT_URL, "https://www.skoob.com.br/v1/prices/$id/");
        
        $retorno = curl_exec($curl);
        curl_close($curl);

        $json = json_decode($retorno);

        //verifica se há um novo menor valor
        $menor_valor = $json->response ? $json->response->preco_min : 300;

        $stmt = $dbh->prepare("SELECT *, strftime('%d/%m/%Y', data_atual) as data_atual FROM livros where id = ? AND id_pessoa = ?");
        $stmt->execute([$id, $id_pessoa]);
        $livro = $stmt->fetch();
        if ($livro) { //Se achou
            echo "Livro já existe no banco de dados \n\r";
            $add_ultimo_valor->bindParam(':menor_valor', $menor_valor);
            $add_ultimo_valor->bindParam(':id', $id);
            $add_ultimo_valor->bindParam(':id_pessoa', $id_pessoa);
            $add_ultimo_valor->execute();

            $valor_livro = $livro['menor_valor'];
            $ultimo = $dbh->query("select valor from last where valor = date('now', 'localtime') and id = $id_pessoa")->fetch();
            if(!$ultimo) {
                echo "É a primeira verificação do dia\n\r";
                $valor_livro = $valor_livro + 5;
            }

            if($valor_livro > $menor_valor && $menor_valor < 300) { //Se o menor valor atual é menor ou até 5 mais caro que o que já existe
                echo "está com um valor menor ou próximo \n\r";
                $promocoes[] = [
                'id' => $id,
                'titulo' => $livro['titulo'], 
                'menor_valor' => $menor_valor, 
                'menor_valor_anterior'=> $livro['menor_valor'],
                'data_anterior' => $livro['data_atual']
                ]; 
                $novo_valor = true;

                if($livro['menor_valor'] > $menor_valor) { //Se for realmente menor salva no bd
                    $atualizar->bindParam(':menor_valor', $menor_valor);
                    $atualizar->bindParam(':id', $id);
                    $atualizar->bindParam(':id_pessoa', $id_pessoa);
                    $atualizar->execute();
                }
            }
        } else { // se não tem um item com esse id cria um novo
            echo "É um livro novo \n\r";
            $inserir->bindParam(':id', $id);
            $inserir->bindParam(':id_pessoa', $id_pessoa);
            $inserir->bindParam(':titulo', $titulo);
            $inserir->bindParam(':menor_valor', $menor_valor);
            $titulo = $lista->edicao->nome_portugues;
            $inserir->execute();
        }
    } catch(PDOException $e) {
        // Print PDOException message
        echo $e->getMessage();
    }
}

$ultima_verificacao->bindParam(':id_pessoa', $id_pessoa);
$ultima_verificacao->execute();

// Close file db connection
$dbh = null;

// Ordernar pelo menor valor
function sortByPrice($a, $b) {   
  return $a['menor_valor'] - $b['menor_valor'];
}

usort($promocoes, "sortByPrice");

if($novo_valor) {
    echo "Há uma promoção \n\r";

    // subject
    $subject = 'Promoção Imperdível';

    // message
    $message = '
    <html>
    <head>
    <title>Olha as promoções imperdíveis</title>
    <style>
    table {
        width: 100%;
        text-align: center;
    }
    th, td {
        border-bottom: 1px solid #654;
    }
    </style>
    </head>
    <body>';

    foreach ($promocoes as $livro) {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_RETURNTRANSFER,1);
        curl_setopt($curl, CURLOPT_URL, "https://www.skoob.com.br/v1/offers/". $livro['id'] . '/');

        $retorno = curl_exec($curl);
        curl_close($curl);

        $ofertas = json_decode($retorno);
        $message .= '
        <h3 style="text-align: center; color:#356dc6">' . $livro['titulo'] . '</h3>
        <table>
        <tr>
            <th>Promoção</th>
            <th>Menor valor antes</th>
            <th>Links</th>
        </tr>
        <tr>
            <td> R$'. $livro['menor_valor'] .'</td>
            <td> R$'. $livro['menor_valor_anterior'] .' - '.  $livro['data_anterior'] . '</td>
            <td>';
            foreach ($ofertas->response->ofertas as $loja) {
                $message .= '<a href="' . $loja->url . '"> <img src="' . $loja->img_url . '" style="height:40px; width:70px;"/> </a>';
            }
        $message .= '</td></tr></table></body></html>';
    }

    // To send HTML mail, the Content-type header must be set
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-type: text/html; charset=utf-8' . "\r\n";

    // Additional headers
    #$headers .= 'To: David <eu@davidsouza.space>' . "\r\n";
    $headers .= 'From: David <eu@davidsouza.space>' . "\r\n";

    // Mail it
    mail($argv[2], $subject, $message, $headers);
}
?>

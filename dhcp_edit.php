<?php
// Define o caminho do arquivo dhcpd.conf na mesma pasta do script PHP em caso de local
//$arquivo_dhcp = __DIR__ . '/conf/dhcpd.conf';

//em caso de servidor
$arquivo_dhcp = 'etc/conf/dhcpd.conf';

// Função para extrair as informações de host do arquivo dhcpd.conf
function extrair_hosts_dhcp($arquivo)
{
    $hosts = [];

    // Verifica se o arquivo existe e pode ser lido
    if (file_exists($arquivo) && is_readable($arquivo)) {
        $conteudo = file_get_contents($arquivo);

        // Usa regex para encontrar os blocos de host conforme o formato fornecido
        preg_match_all('/host\s+(\S+)\s+\{\s+hardware\s+ethernet\s+([\da-fA-F:]+);\s+fixed-address\s+([\d.]+);/s', $conteudo, $matches, PREG_SET_ORDER);

        // Para cada match, adicionar os dados ao array de hosts
        foreach ($matches as $match) {
            $hosts[] = [
                'hostname' => $match[1],
                'mac' => $match[2],
                'ip' => $match[3]
            ];
        }
    }

    return $hosts;
}

// Função para verificar se o hostname, IP ou MAC já existem no arquivo
function verificar_existencia($hosts, $novo_hostname, $novo_ip, $novo_mac, $original_hostname)
{
    foreach ($hosts as $host) {
        // Verificar se o hostname já existe e não é o original
        if ($host['hostname'] === $novo_hostname && $host['hostname'] !== $original_hostname) {
            return 'Hostname já está em uso.';
        }
        // Verificar se o IP já existe
        if ($host['ip'] === $novo_ip && $host['hostname'] !== $original_hostname) {
            return 'IP já está em uso.';
        }
        // Verificar se o MAC já existe
        if ($host['mac'] === $novo_mac && $host['hostname'] !== $original_hostname) {
            return 'MAC Address já está em uso.';
        }
    }
    return false;
}

// Função para validar o formato do IP e MAC
function validar_ip_mac($ip, $mac)
{
    // Validação de IP (deve ter quatro blocos de números separados por pontos)
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return 'Endereço IP inválido.';
    }

    // Validação de MAC (deve ter seis pares de dígitos/alfabetos separados por dois-pontos)
    if (!preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $mac)) {
        return 'Endereço MAC inválido.';
    }

    return false;
}

// Função para atualizar o arquivo dhcpd.conf com o formato correto
function atualizar_dhcp($arquivo, $original_hostname, $novo_hostname, $novo_ip, $novo_mac, $hosts)
{
    // Verificar se o novo hostname, IP ou MAC já existem, exceto para o host original
    $erro = verificar_existencia($hosts, $novo_hostname, $novo_ip, $novo_mac, $original_hostname);
    if ($erro) {
        return $erro;
    }

    // Validação do formato de IP e MAC
    $erro_validacao = validar_ip_mac($novo_ip, $novo_mac);
    if ($erro_validacao) {
        return $erro_validacao;
    }

    // Separar o cabeçalho das configurações e os hosts
    $conteudo_atual = file_get_contents($arquivo);
    $linhas = explode("\n", $conteudo_atual);

    // Preservar as primeiras 12 linhas (cabeçalho)
    $cabecalho = array_slice($linhas, 0, 12);

    // Recriar o array de hosts atualizado
    $novo_hosts = [];
    $host_encontrado = false;

    foreach ($hosts as $host) {
        // Se encontrar o host original, atualiza os dados
        if ($host['hostname'] === $original_hostname) {
            $novo_hosts[] = [
                'hostname' => $novo_hostname,
                'ip' => $novo_ip,
                'mac' => $novo_mac,
            ];
            $host_encontrado = true;
        } else {
            // Caso contrário, mantém o host original
            $novo_hosts[] = $host;
        }
    }

    // Se o host original não foi encontrado, adiciona como novo
    if (!$host_encontrado) {
        $novo_hosts[] = [
            'hostname' => $novo_hostname,
            'ip' => $novo_ip,
            'mac' => $novo_mac,
        ];
    }

    // Reescrever o arquivo dhcpd.conf
    if (file_exists($arquivo) && is_writable($arquivo)) {
        $novo_conteudo = implode("\n", $cabecalho) . "\n\n"; // Adicionar o cabeçalho preservado

        foreach ($novo_hosts as $host) {
            $novo_conteudo .= "host {$host['hostname']} {\n";
            $novo_conteudo .= "    hardware ethernet {$host['mac']};\n";
            $novo_conteudo .= "    fixed-address {$host['ip']};\n";
            $novo_conteudo .= "}\n\n";
        }

        // Salva o novo conteúdo no arquivo
        file_put_contents($arquivo, trim($novo_conteudo));

        // Executa o comando para reiniciar o serviço DHCP
        exec('sudo systemctl restart isc-dhcp-server.service', $output, $return_var);

        // Verifica se o comando foi executado corretamente
        if ($return_var !== 0) {
            return 'Erro ao reiniciar o serviço DHCP: ' . implode("\n", $output);
        }

        return true;
    }

    return 'Erro ao acessar o arquivo dhcpd.conf.';
}

// Função para excluir um host do arquivo dhcpd.conf
function excluir_host_dhcp($arquivo, $hostname)
{
    $conteudo_atual = file_get_contents($arquivo);
    $linhas = explode("\n", $conteudo_atual);

    // Preservar as primeiras 12 linhas (cabeçalho)
    $cabecalho = array_slice($linhas, 0, 12);

    $hosts = extrair_hosts_dhcp($arquivo);
    $novo_hosts = array_filter($hosts, function ($host) use ($hostname) {
        return $host['hostname'] !== $hostname; // Remove apenas o host correspondente
    });

    // Reescrever o arquivo
    if (file_exists($arquivo) && is_writable($arquivo)) {
        $novo_conteudo = implode("\n", $cabecalho) . "\n\n"; // Adicionar o cabeçalho preservado

        foreach ($novo_hosts as $host) {
            $novo_conteudo .= "host {$host['hostname']} {\n";
            $novo_conteudo .= "    hardware ethernet {$host['mac']};\n";
            $novo_conteudo .= "    fixed-address {$host['ip']};\n";
            $novo_conteudo .= "}\n\n";
        }

        file_put_contents($arquivo, trim($novo_conteudo));
        return true;
    }

    return false;
}

// Verificar se o formulário foi enviado para atualizar ou excluir os dados
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $erro = '';
    if (isset($_POST['excluir'])) {
        // Excluir o host
        $hostname = $_POST['hostname'];
        if (excluir_host_dhcp($arquivo_dhcp, $hostname)) {
            header('Location: ' . $_SERVER['PHP_SELF']); // Redireciona para a página atual
            exit;
        } else {
            $erro = 'Erro ao excluir o arquivo.';
        }
    } else {
        // Atualizar o host
        $original_hostname = $_POST['original_hostname'];
        $novo_hostname = $_POST['hostname'];
        $novo_ip = $_POST['ip'];
        $novo_mac = $_POST['mac'];

        // Obter a lista de hosts para verificar existência
        $hosts = extrair_hosts_dhcp($arquivo_dhcp);

        // Atualizar o arquivo dhcpd.conf
        $resultado = atualizar_dhcp($arquivo_dhcp, $original_hostname, $novo_hostname, $novo_ip, $novo_mac, $hosts);
        if ($resultado === true) {
            header('Location: ' . $_SERVER['PHP_SELF']); // Redireciona para a página atual
            exit;
        } else {
            $erro = $resultado; // Mostrar a mensagem de erro se houver
        }
    }
}

// Obter a lista de hosts
$hosts = extrair_hosts_dhcp($arquivo_dhcp);
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Altera DHCP</title>
    <link rel="stylesheet" href="css/dhcpedit.css">
    <link rel="stylesheet" href="css/estilo.css">
    <style>
        /* Estilo para o modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            /* Fundo um pouco mais escuro */
            backdrop-filter: blur(5px);
            /* Efeito de desfoque no fundo */
        }

        .modal-content {
            background-color: #fff;
            /* Cor de fundo branca */
            margin: 10% auto;
            /* Centraliza o modal verticalmente */
            padding: 20px;
            border-radius: 10px;
            /* Bordas arredondadas */
            border: 1px solid #ccc;
            /* Borda cinza clara */
            width: 80%;
            max-width: 500px;
            /* Limita a largura máxima */
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            /* Sombra para profundidade */
            transition: all 0.3s ease;
            /* Transição suave */
            text-align: center;
            /* Centraliza o texto */
        }

        .close {
            color: #ff5c5c;
            /* Cor do botão de fechar */
            float: right;
            font-size: 28px;
            font-weight: bold;
        }

        .close:hover,
        .close:focus {
            color: #d9534f;
            /* Cor mais intensa ao passar o mouse */
            text-decoration: none;
            cursor: pointer;
        }

        .erro {
            color: #d9534f;
            /* Cor para mensagens de erro */
            font-weight: bold;
            margin-bottom: 15px;
            /* Espaçamento inferior */
        }

        /* Estilo para os campos de entrada */
        input[type="text"] {
            width: 90%;
            /* Define a largura dos inputs */
            padding: 10px;
            /* Adiciona preenchimento interno */
            margin: 10px 0;
            /* Espaçamento vertical */
            border: 1px solid #ccc;
            /* Borda cinza clara */
            border-radius: 5px;
            /* Bordas arredondadas */
            text-align: center;
            /* Centraliza o texto dentro do input */
        }

        /* Estilo para os botões dentro do modal */
        button {
            background-color: #007bff;
            /* Cor do botão */
            color: white;
            /* Cor do texto */
            border: none;
            /* Sem borda */
            padding: 4px 3px;
            /* Espaçamento interno */
            border-radius: 5px;
            /* Bordas arredondadas */
            cursor: pointer;
            /* Cursor de ponteiro */
            transition: background-color 0.3s ease;
            /* Transição suave de cor */
            margin-top: 8px;
            /* Espaçamento acima do botão */
        }

        button:hover {
            background-color: #0056b3;
            /* Cor ao passar o mouse */
        }

        a {
            margin-right: 80%;
        }
    </style>
</head>

<body>

    <section>
        <a href="dhcp_include.php"><button>Reservar Endereços</button></a>
        <h3>Endereços Cadastrados:</h3>
        <?php if (!empty($erro)) {
            echo '<p class="erro">' . htmlspecialchars($erro) . '</p>';
        } ?>
        <table>
            <thead>
                <tr>
                    <th scope="col">Host Name</th>
                    <th scope="col">IP</th>
                    <th scope="col">MAC</th>
                    <th scope="col">Editar</th>
                    <th scope="col">Excluir</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (!empty($hosts)) {
                    foreach ($hosts as $host) {
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($host['hostname']) . '</td>';
                        echo '<td>' . htmlspecialchars($host['ip']) . '</td>';
                        echo '<td>' . htmlspecialchars($host['mac']) . '</td>';
                        echo '<td><button onclick="openModal(\'' . htmlspecialchars($host['hostname']) . '\', \'' . htmlspecialchars($host['ip']) . '\', \'' . htmlspecialchars($host['mac']) . '\')">Editar</button></td>';
                        echo '<td><button onclick="confirmarExclusao(\'' . htmlspecialchars($host['hostname']) . '\')">Excluir</button></td>';
                        echo '</tr>';
                    }
                } else {
                    echo '<tr><td colspan="5">Nenhum endereço cadastrado.</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </section>

    <!-- Modal de edição -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2>Editar Host</h2>
            <form method="POST">
                <label for="hostname">Host Name:</label>
                <input type="text" id="hostname" name="hostname" required><br><br>

                <label for="ip">IP Address:</label>
                <input type="text" id="ip" name="ip" required><br><br>

                <label for="mac">MAC Address:</label>
                <input type="text" id="mac" name="mac" required><br><br>

                <input type="hidden" id="original_hostname" name="original_hostname">

                <button type="submit">Atualizar</button>
            </form>
        </div>
    </div>

    <form id="excluirForm" method="POST">
        <input type="hidden" name="hostname" id="excluirHostname">
        <input type="hidden" name="excluir" value="1">
    </form>

    <script>
        // Função para abrir o modal com os dados atuais
        function openModal(hostname, ip, mac) {
            document.getElementById('hostname').value = hostname;
            document.getElementById('ip').value = ip;
            document.getElementById('mac').value = mac;
            document.getElementById('original_hostname').value = hostname;
            document.getElementById('editModal').style.display = "block";
        }

        // Função para fechar o modal
        function closeModal() {
            document.getElementById('editModal').style.display = "none";
        }

        // Função para confirmar a exclusão
        function confirmarExclusao(hostname) {
            if (confirm('Tem certeza que deseja excluir o host ' + hostname + '?')) {
                document.getElementById('excluirHostname').value = hostname;
                document.getElementById('excluirForm').submit();
            }
        }
    </script>
</body>

</html>
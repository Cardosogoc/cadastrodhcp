<?php
// Define o caminho do arquivo dhcpd.conf na mesma pasta do script PHP
$arquivo_dhcp = __DIR__ . '/conf/dhcpd.conf';

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
function verificar_existencia($hosts, $novo_hostname, $novo_ip, $novo_mac)
{
    foreach ($hosts as $host) {
        // Verificar se o hostname já existe
        if ($host['hostname'] === $novo_hostname) {
            return 'Hostname já está em uso.';
        }
        // Verificar se o IP já existe
        if ($host['ip'] === $novo_ip) {
            return 'IP já está em uso.';
        }
        // Verificar se o MAC já existe
        if ($host['mac'] === $novo_mac) {
            return 'MAC Address já está em uso.';
        }
    }
    return false;
}

// Função para validar formato do IP
function validar_ip($ip)
{
    // Regex para validar um IP no formato IPv4 (4 grupos de números separados por '.')
    return filter_var($ip, FILTER_VALIDATE_IP);
}

// Função para validar formato do MAC
function validar_mac($mac)
{
    // Regex para validar um MAC Address (6 grupos de números hexadecimais separados por ':')
    return preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $mac);
}

// Função para adicionar um novo host ao arquivo dhcpd.conf
function adicionar_host_dhcp($arquivo, $novo_hostname, $novo_ip, $novo_mac, $hosts)
{
    // Verificar se o novo hostname, IP ou MAC já existem
    $erro = verificar_existencia($hosts, $novo_hostname, $novo_ip, $novo_mac);
    if ($erro) {
        return $erro;
    }

    // Verificar se o IP e MAC são válidos
    if (!validar_ip($novo_ip)) {
        return 'Endereço IP inválido. Deve estar no formato correto (ex: 192.168.1.1).';
    }

    if (!validar_mac($novo_mac)) {
        return 'MAC Address inválido. Deve estar no formato correto (ex: 00:1A:2B:3C:4D:5E).';
    }

    if (file_exists($arquivo) && is_writable($arquivo)) {
        // Criar novo bloco para o host
        $novo_bloco = "\nhost $novo_hostname {\n    hardware ethernet $novo_mac;\n    fixed-address $novo_ip;\n";

        // Adicionar o novo bloco ao final do arquivo
        file_put_contents($arquivo, $novo_bloco, FILE_APPEND);

        return true;
    }
    return false;
}

// Verificar se o formulário foi enviado para adicionar os dados
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $novo_hostname = $_POST['hostname'];
    $novo_ip = $_POST['ip'];
    $novo_mac = $_POST['mac'];

    // Obter a lista de hosts para verificar existência
    $hosts = extrair_hosts_dhcp($arquivo_dhcp);

    // Adicionar o novo host ao arquivo dhcpd.conf
    $resultado = adicionar_host_dhcp($arquivo_dhcp, $novo_hostname, $novo_ip, $novo_mac, $hosts);
    if ($resultado === true) {
        header('Location: ' . $_SERVER['PHP_SELF']); // Redireciona para a página atual
        exit;
    } else {
        $erro = $resultado; // Mostrar a mensagem de erro se houver
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservar Endereço DHCP</title>
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
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: #fff;
            margin: 10% auto;
            padding: 20px;
            border-radius: 10px;
            border: 1px solid #ccc;
            width: 80%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
            text-align: center;
        }

        .close {
            color: #ff5c5c;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }

        .close:hover,
        .close:focus {
            color: #d9534f;
            text-decoration: none;
            cursor: pointer;
        }

        .erro {
            color: #d9534f;
            font-weight: bold;
            margin-bottom: 15px;
        }

        /* Estilo para os campos de entrada */
        input[type="text"] {
            width: 90%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ccc;
            border-radius: 5px;
            text-align: center;
        }

        /* Estilo para os botões */
        button {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 5px 7px;
            border-radius: 5px;
            cursor: pointer;
            transition: background-color 0.3s ease;
            margin-top: 8px;
        }

        button:hover {
            background-color: #0056b3;
        }

        /* Estilo para o botão discreto */
        .botao-discreto {
            background-color: transparent;
            color: #007bff;
            border: none;
            cursor: pointer;
            text-decoration: underline;
            margin-top: 10px;
        }

        .botao-discreto:hover {
            color: #0056b3;
        }
    </style>
</head>

<body>
    <section>
        <h3>Reservar Novo Endereço DHCP:</h3>
        <?php if (!empty($erro)) {
            echo '<p class="erro">' . htmlspecialchars($erro) . '</p>';
        } ?>
        <form method="POST">
            <label for="hostname">Host Name:</label>
            <input type="text" id="hostname" name="hostname" required><br><br>

            <label for="ip">IP Address:</label>
            <input type="text" id="ip" name="ip" required><br><br>

            <label for="mac">MAC Address:</label>
            <input type="text" id="mac" name="mac" required><br><br>

            <button type="submit">Reservar Endereço</button>
        </form>

        <!-- Botão discreto para redirecionar para dhcp_edit.php -->
        <form action="dhcp_edit.php" method="get" style="margin-top: 20px;">
            <button type="submit" class="botao-discreto">Ir para Edição de DHCP</button>
        </form>
    </section>
</body>

</html>

<?php
    $host = '104.154.142.250';
    $username = 'srmotgnp24';
    $password = 'nj56q1npL93aG3eo';
    $dbname = 'gnp';
    $conn = new mysqli($host, $username, $password, $dbname);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    $sql = 'SELECT * FROM policies';
    $dbPolicies = $conn->query($sql);
    $uuidPolicies = [];
    while ($row = $dbPolicies->fetch_assoc()) {
        $uuidPolicies[] = $row["uuid"];
    }
?>

<?php
    function rrFilter($report) {
        return (isset($report["estatusRobo"]["clave"]) && $report["estatusRobo"]["clave"] == "RR");
    }
?>

<?php
    $ch = curl_init();
    $url = "https://api-gc-uat.service.gnp.com.mx/wfdlcore/api/proveedor/tramites";
    $dataBody = [
        "codSiniestro" => "",
        "cveEstado" => "",
        "cveEstatusInstalacion" => "",
        "cveEstatusPoliza" => "",
        "cveEstatusRecibo" => "",
        "cveEstatusRobo" => "",
        "cveEstatusTramite" => "",
        "cveMarca" => "",
        "cveSubmarca" => "",
        "cveTipoUso" => "",
        "cveTipoVehiculo" => "",
        "modelo" => "",
        "numPoliza" => "",
        "rangoAsignado" => [
            "fchFin" => "2025-01-28",
            "fchInicio" => "2024-10-01"
        ],
        "rangoCreacion" => [
            "fchFin" => "",
            "fchInicio" => ""
        ],
        "rangoSiniestro" => [
            "fchFin" => "",
            "fchInicio" => ""
        ],
        "rangoVigencia" => [
            "fchFin" => "",
            "fchInicio" => ""
        ],
        "renovar" => "",
        "vin" => ""
    ];
    $json_data = json_encode($dataBody);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "accept: application/json;charset=UTF-8",
        'tokenProvider: $2a$10$OnNNvsnJbjb9K8Tb.10NEOUE92juS16B.YW6fPnR78s6PRv/E.0we',
        "Content-Type: application/json;charset=UTF-8"
    ]);
    $json = curl_exec($ch);
    if (curl_errno($ch)) {
        echo 'cURL error: ' . curl_error($ch);
    } else {
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($http_code == 200 || $http_code == 201) {
            $data = json_decode($json, true);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
                echo "JSON parsing error: " . json_last_error_msg();
            } else {
                $filteredRrReports = array_filter($data["content"], "rrFilter");
                $values = [];
                foreach ($filteredRrReports as $key => $report) {
                    if (!in_array($report["uuidTramite"], $uuidPolicies)) {
                        $uuid = $report["uuidTramite"];
                        $status_tramite = $report["estatusTramite"]["clave"] . "-" . $report["estatusTramite"]["descripcion"];
                        $status_robo = $report["estatusRobo"]["clave"] . "-" . $report["estatusRobo"]["descripcion"];
                        $vin = $report["vin"];
                        $placas = $report["placas"];
                        $values[] = [$uuid, $status_tramite, $status_robo, $vin, $placas];
                    }
                }
                $sql = "INSERT INTO policies (uuid, status_tramite, status_robo, vin, placas) VALUES (?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    die("Error en la preparación: " . $conn->error);
                }
                foreach ($values as $row) {
                    $stmt->bind_param("sssss", $row[0], $row[1], $row[2], $row[3], $row[4]);
                    $stmt->execute();
                }
                echo "Datos insertados correctamente";
                $stmt->close();
                $conn->close();
            }
        } else {
            echo "HTTP request error, status code: " . $http_code;
        }
    }
    curl_close($ch);
?>
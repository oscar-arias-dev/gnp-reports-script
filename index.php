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
            "fchFin" => date("Y-m-d"),
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
                $vins = [];
                foreach ($filteredRrReports as $key => $report) {
                    if (!in_array($report["uuidTramite"], $uuidPolicies)) {
                        $uuid = $report["uuidTramite"];
                        $status_tramite = $report["estatusTramite"]["clave"] . "-" . $report["estatusTramite"]["descripcion"];
                        $status_robo = $report["estatusRobo"]["clave"] . "-" . $report["estatusRobo"]["descripcion"];
                        $vin = $report["vin"];
                        $placas = $report["placas"];
                        if (isset($report["vin"]) && $report["vin"] !== "") {
                            $vins[] = $report["vin"];
                        }
                        $values[] = [$uuid, $status_tramite, $status_robo, $vin, $placas, ""];
                    }
                }
                /* $values[] = ["testvin", "testvin", "testvin", "3AKJHPDV1RSVK0034", "testvin", ""];
                $vins[] = "3AKJHPDV1RSVK0034"; */
                $vinsBody = ["vin" => $vins];
                $jsonVinsData = json_encode($vinsBody);
                $ci = curl_init();
                curl_setopt($ci, CURLOPT_URL, "https://secure.tecnomotum.com/apis/gnp/vinlocator");
                curl_setopt($ci, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ci, CURLOPT_HEADER, false);
                curl_setopt($ci, CURLOPT_POST, true);
                curl_setopt($ci, CURLOPT_POSTFIELDS, $jsonVinsData);
                curl_setopt($ci, CURLOPT_HTTPHEADER, [
                    "accept: application/json;charset=UTF-8",
                    "Content-Type: application/json;charset=UTF-8"
                ]);
                $jsonVins = curl_exec($ci);
                $dataArray = json_decode($jsonVins, true);
                foreach ($values as $keyRr => $item) {
                    if (array_key_exists($item[3], $dataArray)) {
                        if ($dataArray[$item[3]]["located"]) {
                            $values[$keyRr][6] = 1;
                        } else {
                            $values[$keyRr][6] = 0;
                        }
                    }
                }
                $sql = "INSERT INTO policies (uuid, status_tramite, status_robo, vin, placas, motum_status, motum_vin) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    die("Error en la preparación: " . $conn->error);
                }
                foreach ($values as $row) {
                    $stmt->bind_param("ssssssi", $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6]);
                    $stmt->execute();
                }
                echo "Datos insertados correctamente";
                $stmt->close();
                $conn->close();
                $withVin = [];
                foreach ($values as $current) {
                    if ($current[6] === 1) {
                        $withVin[] = $current[3];
                    }
                }
                if (count($withVin) > 0) {
                    $joinedVins = implode(", ", $withVin);
                    $waMessage = "Las unidades con VIN: " . $joinedVins . " tienen poliza de robo activa.";
                    $waBody = [
                        "message" => $waMessage,
                        "number" => "", // ADD NUMBER
                        "type" => "person"
                    ];
                    $cj = curl_init();
                    curl_setopt($cj, CURLOPT_URL, "https://secure.tecnomotum.com/apis/wsp/send");
                    curl_setopt($cj, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($cj, CURLOPT_HEADER, false);
                    curl_setopt($cj, CURLOPT_POST, true);
                    curl_setopt($cj, CURLOPT_POSTFIELDS, json_encode($waBody));
                    curl_setopt($cj, CURLOPT_HTTPHEADER, [
                        "accept: application/json;charset=UTF-8",
                        "Content-Type: application/json;charset=UTF-8"
                    ]);
                    curl_exec($cj);
                    curl_close($cj);
                }
            }
        } else {
            echo "HTTP request error, status code: " . $http_code;
        }
    }
    curl_close($ch);
    curl_close($ci);
?>
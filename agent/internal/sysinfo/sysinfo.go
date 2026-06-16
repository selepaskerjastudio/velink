// Package sysinfo collects static system information for the sysinfo message
// sent to the panel on first connect.
package sysinfo

import (
	"bufio"
	"net"
	"os"
	"os/exec"
	"strings"
)

// Info holds the static system metadata sent once after connecting.
type Info struct {
	Hostname  string `json:"hostname"`
	PrivateIP string `json:"private_ip"`
	OS        string `json:"os"`
}

// Collect gathers hostname, private IP, and OS information. If a field cannot
// be determined it is left as an empty string — never fatal.
func Collect() Info {
	return Info{
		Hostname:  collectHostname(),
		PrivateIP: collectPrivateIP(),
		OS:        collectOS(),
	}
}

func collectHostname() string {
	h, err := os.Hostname()
	if err != nil {
		return ""
	}
	return h
}

// collectPrivateIP iterates network interfaces and returns the first non-loopback
// IPv4 address it finds on an interface that is up.
func collectPrivateIP() string {
	ifaces, err := net.Interfaces()
	if err != nil {
		return ""
	}
	for _, iface := range ifaces {
		// Skip loopback and down interfaces.
		if iface.Flags&net.FlagLoopback != 0 || iface.Flags&net.FlagUp == 0 {
			continue
		}
		addrs, err := iface.Addrs()
		if err != nil {
			continue
		}
		for _, addr := range addrs {
			var ip net.IP
			switch v := addr.(type) {
			case *net.IPNet:
				ip = v.IP
			case *net.IPAddr:
				ip = v.IP
			}
			if ip == nil || ip.IsLoopback() {
				continue
			}
			if ip4 := ip.To4(); ip4 != nil {
				return ip4.String()
			}
		}
	}
	return ""
}

// collectOS parses /etc/os-release for PRETTY_NAME and falls back to
// `uname -sr` if the file is absent or lacks the key.
func collectOS() string {
	if name := osReleasePrettyName(); name != "" {
		return name
	}
	return unameSR()
}

func osReleasePrettyName() string {
	f, err := os.Open("/etc/os-release")
	if err != nil {
		return ""
	}
	defer f.Close()

	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		line := scanner.Text()
		if !strings.HasPrefix(line, "PRETTY_NAME=") {
			continue
		}
		val := strings.TrimPrefix(line, "PRETTY_NAME=")
		// Strip surrounding quotes if present.
		val = strings.Trim(val, `"'`)
		return val
	}
	return ""
}

func unameSR() string {
	out, err := exec.Command("uname", "-sr").Output()
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(out))
}
